<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\GeminiClient;
use App\Classes\TestAI\Infra\HtmlSanitizer;
use App\Classes\TestAI\Repository\DailyRepository;
use App\Classes\TestAI\Repository\MessageRepository;
use App\Classes\TestAI\Repository\SettingsRepository;

class AnnouncementService {
    public function __construct(
        private string              $model,
        private GeminiClient        $gemini,
        private HtmlSanitizer       $sanitizer,
        private DailyRepository     $dailyRepo,
        private MessageRepository   $msgRepo,
        private SettingsRepository  $settings,
        private string              $cacheDir
    ) {}

    public function getCached(string $date): string {
        $f = $this->cacheFile($date);
        return is_file($f) ? (string)file_get_contents($f) : '';
    }

    public function generate(string $date): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        $events    = $this->collectEvents($date);
        $todayMsgs = $this->collectTodayMessages($date);

        $system = trim($this->settings->get('bot_system_announce'));
        if ($system === '') {
            $system = "Return HTML only. No markdown. No scripts. Use simple tags: div,p,br,strong,em,ul,li,h2,h3,a,span.";
        }
        $lang    = $this->detectLang(json_encode([$events, $todayMsgs], JSON_UNESCAPED_UNICODE) ?: '');
        $system .= "\n\nWrite the announcement in " . strtoupper($lang) . ".";

        $prompt = $lang === 'ru'
            ? "Сформируй короткий HTML-анонс для ресторана на дату {$date}. Если информации нет — верни HTML с коротким сообщением, что подтверждённого анонса пока нет."
            : "Create a short HTML announcement for the restaurant for date {$date}. If no info available, say no confirmed announcement yet.";

        $resp = $this->gemini->generate(
            $this->model,
            [['text' => $prompt], ['text' => json_encode(['date' => $date, 'events' => $events, 'today_messages' => $todayMsgs], JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => 0.3, 'maxOutputTokens' => 2200, 'tag' => 'announce_generate']
        );
        $html = $this->sanitizer->sanitizeHtml($this->gemini->text($resp));

        // Retry in Russian if output came out in wrong language
        if ($lang === 'ru' && $this->hasExcessiveLatin(strip_tags($html))) {
            $resp2 = $this->gemini->generate(
                $this->model,
                [['text' => $prompt], ['text' => json_encode(['date' => $date, 'events' => $events], JSON_UNESCAPED_UNICODE)]],
                ['system' => $system . "\n\nOutput MUST be in Russian.", 'temperature' => 0.2, 'maxOutputTokens' => 2200, 'tag' => 'announce_ru_fix']
            );
            $html2 = $this->sanitizer->sanitizeHtml($this->gemini->text($resp2));
            if ($html2 !== '') $html = $html2;
        }

        if ($html !== '') {
            if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0775, true);
            @file_put_contents($this->cacheFile($date), $html, LOCK_EX);
        }
        return $html;
    }

    private function collectEvents(string $announceDate): array {
        $since  = date('Y-m-d', strtotime('-90 day')) . ' 00:00:00';
        $events = [];
        foreach ($this->dailyRepo->listSince($since) as $r) {
            $ej = json_decode((string)($r['events_json'] ?? '[]'), true);
            if (!is_array($ej)) continue;
            foreach ($ej as $ev) {
                if (is_array($ev) && (string)($ev['announce_date'] ?? '') === $announceDate) {
                    $events[] = $ev;
                }
            }
        }
        return $events;
    }

    private function collectTodayMessages(string $date): array {
        if ($date !== date('Y-m-d')) return [];
        $out = [];
        foreach ($this->msgRepo->fetchForRange($date . ' 00:00:00', $date . ' 23:59:59') as $r) {
            $txt  = trim((string)($r['text'] ?? ''));
            $mt   = trim((string)($r['media_text'] ?? ''));
            $body = trim($txt . ($mt !== '' ? "\n[media]\n" . $mt : ''));
            if ($body === '') continue;
            $out[] = [
                'received_at' => (string)($r['received_at'] ?? ''),
                'chat_title'  => (string)($r['tg_chat_title'] ?? ''),
                'from'        => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
                'text'        => $body,
            ];
        }
        return $out;
    }

    private function cacheFile(string $date): string {
        return rtrim($this->cacheDir, '/\\') . '/announce_' . $date . '.html';
    }

    private function detectLang(string $text): string {
        if (preg_match('/\p{Cyrillic}/u', $text)) return 'ru';
        return 'en';
    }

    private function hasExcessiveLatin(string $s): bool {
        if ($s === '') return false;
        preg_match_all('/[A-Za-z]/', $s, $m);
        return count($m[0] ?? []) >= 40;
    }
}
