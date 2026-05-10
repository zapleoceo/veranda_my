<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\GeminiClient;
use App\Classes\TestAI\Repository\DailyRepository;
use App\Classes\TestAI\Repository\MessageRepository;
use App\Classes\TestAI\Repository\SettingsRepository;

class DailySummaryService {
    public function __construct(
        private string              $model,
        private GeminiClient        $gemini,
        private MessageRepository   $msgRepo,
        private DailyRepository     $dailyRepo,
        private SettingsRepository  $settings
    ) {}

    public function runDay(string $day): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return false;

        $rows  = $this->msgRepo->fetchForRange($day . ' 00:00:00', $day . ' 23:59:59');
        $items = [];
        foreach ($rows as $r) {
            $txt  = trim((string)($r['text'] ?? ''));
            $mt   = trim((string)($r['media_text'] ?? ''));
            $body = trim($txt . ($mt !== '' ? "\n[media]\n" . $mt : ''));
            if ($body === '') continue;
            $items[] = [
                'tg_chat_id'    => (string)($r['tg_chat_id'] ?? ''),
                'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
                'received_at'   => (string)($r['received_at'] ?? ''),
                'chat_title'    => (string)($r['tg_chat_title'] ?? ''),
                'from'          => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
                'text'          => $body,
            ];
        }

        $system = trim($this->settings->get('bot_system_daily'));
        if ($system === '') {
            $system = "Return strict JSON only with keys: summary_text (string), events (array)."
                    . " Each event: announce_date (YYYY-MM-DD), title, facts (array of strings), confidence (0..100),"
                    . " sources (array of {tg_chat_id,tg_message_id}).";
        }
        $lang = $this->detectLang(json_encode($items, JSON_UNESCAPED_UNICODE) ?: '');
        $system .= "\n\nAll string fields must be in " . strtoupper($lang) . ".";

        $prompt = $lang === 'ru'
            ? "Сделай сводку активности чата за {$day} и извлеки анонсы ресторана. Если анонсов нет — events должен быть пустым массивом."
            : "Summarize chat activity for {$day} and extract restaurant announcements. If none — events must be [].";

        $resp = $this->gemini->generate(
            $this->model,
            [['text' => $prompt], ['text' => json_encode(['day' => $day, 'messages' => $items], JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => 0.2, 'maxOutputTokens' => 2500, 'responseMimeType' => 'application/json', 'tag' => 'daily_summary']
        );

        $j = $this->gemini->json($resp);
        if (!is_array($j)) return false;

        // Retry in Russian if output came out in wrong language
        if ($lang === 'ru' && $this->hasExcessiveLatin((string)($j['summary_text'] ?? ''))) {
            $resp2 = $this->gemini->generate(
                $this->model,
                [['text' => json_encode(['lang' => 'ru', 'data' => $j], JSON_UNESCAPED_UNICODE)]],
                ['system' => $system . "\n\nTranslate all string fields to RU. Keep JSON schema.", 'temperature' => 0.1, 'maxOutputTokens' => 2500, 'responseMimeType' => 'application/json', 'tag' => 'daily_summary_ru_fix']
            );
            $j2 = $this->gemini->json($resp2);
            if (is_array($j2)) $j = $j2;
        }

        $summary    = trim((string)($j['summary_text'] ?? ''));
        $events     = is_array($j['events'] ?? null) ? $j['events'] : [];
        $eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE) ?: '[]';

        $this->dailyRepo->upsert($day, $summary, $eventsJson, date('Y-m-d H:i:s'));
        return true;
    }

    private function detectLang(string $text): string {
        if (preg_match('/\p{Cyrillic}/u', $text)) return 'ru';
        return 'en';
    }

    private function hasExcessiveLatin(string $s): bool {
        if ($s === '') return false;
        preg_match_all('/[A-Za-z]/', $s, $m);
        return count($m[0] ?? []) >= 20;
    }
}
