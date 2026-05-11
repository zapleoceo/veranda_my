<?php

declare(strict_types=1);

namespace App\Classes\TestAI\App;

use App\Classes\TestAI\Infra\GeminiClient;
use App\Classes\TestAI\Infra\TelegramClient;
use App\Classes\TestAI\Infra\Logger;
use App\Classes\TestAI\Repository\MessageRepository;
use App\Classes\TestAI\Repository\DailyRepository;
use App\Classes\TestAI\Repository\SettingsRepository;
use App\Classes\TestAI\Service\DailySummaryService;
use App\Classes\TestAI\Service\Responder;

/**
 * Entry point for Telegram webhook updates.
 *
 * Responsibilities:
 *   - Extract message from update
 *   - Filter by allowed chats
 *   - Save message to DB
 *   - OCR media if needed (Gemini)
 *   - Handle /summary command
 *   - Check Gemini rate limits
 *   - Call Responder (1 Gemini call) and send reply
 */
class WebhookHandler {
    /** Chats explicitly whitelisted (can see KB docs with access=members). Empty = open mode (all groups allowed). */
    private array $allowedChatIds;
    /** When true, all group chats are accepted (allowedChatIds not configured). */
    private bool $openMode;

    public function __construct(
        private string              $model,
        private GeminiClient        $gemini,
        private TelegramClient      $tg,
        private MessageRepository   $msgRepo,
        private DailyRepository     $dailyRepo,
        private SettingsRepository  $settings,
        private DailySummaryService $dailySvc,
        private Responder           $responder,
        private Logger              $log,
        array                       $allowedChatIds = []
    ) {
        $this->openMode       = empty($allowedChatIds);
        $this->allowedChatIds = array_flip(array_map('strval', $allowedChatIds));
    }

    public function handleUpdate(array $update): void {
        if (!$this->tg->hasToken()) {
            $this->log->error('tg_token_missing');
            return;
        }

        $msg = $this->extractMessage($update);
        if ($msg === null) return;

        $chat     = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
        $chatType = (string)($chat['type'] ?? 'unknown');
        $chatId   = isset($chat['id']) ? (string)$chat['id'] : '';
        if ($chatId === '') return;

        // Non-private chats: reject if a whitelist is configured and this chat isn't in it.
        // Open mode (no whitelist): accept all groups the bot is already a member of.
        if ($chatType !== 'private' && !$this->openMode && !isset($this->allowedChatIds[$chatId])) return;

        // Authorized = private chat OR explicitly whitelisted group (or all groups in open mode)
        $isAuthorized = $chatType === 'private' || $this->openMode || isset($this->allowedChatIds[$chatId]);

        $chatTitle = (string)($chat['title'] ?? '');
        $messageId = isset($msg['message_id']) ? (int)$msg['message_id'] : 0;
        $receivedAt = date('Y-m-d H:i:s', isset($msg['date']) ? (int)$msg['date'] : time());

        $from     = is_array($msg['from'] ?? null) ? $msg['from'] : [];
        $userId   = isset($from['id']) ? (int)$from['id'] : null;
        $username = trim((string)($from['username'] ?? ''));
        $name     = trim(trim((string)($from['first_name'] ?? '')) . ' ' . trim((string)($from['last_name'] ?? '')));

        $text    = trim((string)($msg['text'] ?? ''));
        $caption = trim((string)($msg['caption'] ?? ''));
        if ($text === '' && $caption !== '') $text = $caption;

        [$needReply, $queryText] = $this->detectNeedReply($chatType, $text);
        $cmdDay = $this->parseSummaryCommand($text);

        $this->log->info('webhook_message', [
            'chat_id'         => $chatId,
            'chat_type'       => $chatType,
            'message_id'      => $messageId,
            'has_text'        => $text !== '' ? 1 : 0,
            'text_head'       => mb_substr($text, 0, 120),
            'need_reply'      => $needReply ? 1 : 0,
            'is_summary'      => $cmdDay !== null ? 1 : 0,
            'is_authorized'   => $isAuthorized ? 1 : 0,
            'can_call_gemini' => $this->gemini->canCall() ? 1 : 0,
        ]);

        // Build message record
        $media = $this->extractMedia($msg);
        $record = [
            'chat_id'             => $chatId,
            'chat_type'           => $chatType,
            'chat_title'          => $chatTitle,
            'message_id'          => $messageId,
            'user_id'             => $userId,
            'username'            => ltrim(strtolower($username), '@'),
            'name'                => $name,
            'received_at'         => $receivedAt,
            'text'                => $text,
            'media_type'          => $media['type'],
            'media_file_id'       => $media['file_id'],
            'media_file_unique_id'=> $media['file_unique_id'],
            'media_mime'          => $media['mime'],
            'media_duration_sec'  => $media['duration_sec'],
            'media_text'          => null,
            'meta_json'           => json_encode(['has_media' => $media['type'] ? 1 : 0]) ?: '{}',
            'importance'          => 5,
        ];
        $this->msgRepo->upsert($record);

        // Handle /summary command
        if ($cmdDay !== null) {
            $this->log->info('summary_command', ['chat_id' => $chatId, 'day' => $cmdDay]);
            $this->handleSummaryCommand($chatId, $messageId, $cmdDay);
            return;
        }

        // OCR / transcribe media if bot needs to reply
        if ($this->gemini->canCall() && $media['type'] && $media['file_id'] && ($needReply || $chatType === 'private')) {
            $mediaText = $this->extractMediaText($media['file_id'], $media['mime'] ?: 'application/octet-stream');
            if ($mediaText !== '') {
                $record['media_text'] = $mediaText;
                $this->msgRepo->updateMediaText($chatId, $messageId, $mediaText);
                if (trim($queryText) === '') $queryText = $mediaText;
            }
        }

        if (!$needReply || trim($queryText) === '' || !$this->gemini->canCall()) {
            $this->log->info('webhook_skip_reply', [
                'chat_id'   => $chatId,
                'need_reply'=> $needReply ? 1 : 0,
                'query_empty'=> trim($queryText) === '' ? 1 : 0,
                'can_gemini' => $this->gemini->canCall() ? 1 : 0,
            ]);
            return;
        }

        if ($this->isGreeting($queryText)) {
            $this->tg->sendMessage($chatId, 'Привет! Я бот Veranda. Могу подсказать по меню, напиткам, ценам, времени работы и контактам.', $chatType === 'private' ? null : $messageId);
            return;
        }

        // Rate limit check
        $waitSec = $this->geminiWaitSec();
        if ($waitSec > 0) {
            $this->log->info('gemini_wait', ['chat_id' => $chatId, 'wait_sec' => $waitSec]);
            $this->tg->sendMessage($chatId, 'Лимит запросов к AI исчерпан. Попробуйте через ' . (int)ceil($waitSec) . ' сек.', null);
            return;
        }

        // Build context from recent messages in this chat
        $context = $this->buildContext($chatId);

        // Single Gemini call via Responder
        $html = $this->responder->respond($queryText, $context, $isAuthorized);
        if ($html === '') $html = 'Не получилось сформировать ответ.';

        // 4-second cooldown between Gemini calls
        $this->settings->set('gemini_next_allowed_until', gmdate('c', time() + 4));

        $replyTo = $chatType === 'private' ? null : $messageId;
        $ok = $this->tg->sendMessage($chatId, $html, $replyTo);
        $this->log->info('telegram_send_result', ['chat_id' => $chatId, 'message_id' => $messageId, 'ok' => $ok ? 1 : 0]);
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function extractMessage(array $update): ?array {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $k) {
            if (!empty($update[$k]) && is_array($update[$k])) return $update[$k];
        }
        return null;
    }

    private function detectNeedReply(string $chatType, string $text): array {
        if ($chatType === 'private') return [trim($text) !== '', $text];
        if (preg_match('/^\/(?:ai|ask)(?:@\w+)?\s+([\s\S]+)$/u', $text, $m)) {
            return [true, trim((string)($m[1] ?? ''))];
        }
        return [false, $text];
    }

    private function parseSummaryCommand(string $text): ?string {
        $t = trim($text);
        if (!preg_match('/^\/summary(?:@\w+)?(?:\s+([0-9]{4}(?:-[0-9]{1,2}){0,2}))?\s*$/u', $t, $m)) return null;
        $raw = trim((string)($m[1] ?? ''));
        if ($raw === '') return date('Y-m-d');
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $mm)) {
            [$y, $mo, $d] = [(int)$mm[1], (int)$mm[2], (int)$mm[3]];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : date('Y-m-d');
        }
        return date('Y-m-d');
    }

    private function handleSummaryCommand(string $chatId, int $messageId, string $day): void {
        $waitSec = $this->geminiWaitSec();
        $row     = $this->dailyRepo->getByDay($day);

        // Check if summary needs regeneration (exists but Russian text has too much Latin)
        $needsRegen = false;
        if (is_array($row)) {
            $summary = (string)($row['summary_text'] ?? '');
            if ($summary !== '') {
                preg_match_all('/[A-Za-z]/', $summary, $lm);
                if (count($lm[0] ?? []) >= 20) $needsRegen = true;
            }
        }

        if (is_array($row) && !$needsRegen) {
            $summary = (string)($row['summary_text'] ?? '');
            $text    = $summary !== '' ? "Сводка за {$day}:\n\n" . $summary : "Сводка за {$day} пуста.";
            $this->tg->sendMessage($chatId, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $messageId);
            return;
        }

        if ($waitSec > 0 && $waitSec > 70) {
            $this->tg->sendMessage($chatId, 'Лимит запросов. Попробуйте через ' . (int)ceil($waitSec) . ' сек.', $messageId);
            return;
        }
        if ($waitSec > 0) {
            $this->tg->sendMessage($chatId, 'Лимит запросов. Попробуйте через ' . (int)ceil($waitSec) . ' сек.', $messageId);
            return;
        }

        $ok  = $this->dailySvc->runDay($day);
        $row = $this->dailyRepo->getByDay($day);
        if ($ok && is_array($row)) {
            $summary = (string)($row['summary_text'] ?? '');
            $text    = $summary !== '' ? "Сводка за {$day}:\n\n" . $summary : "Сводка за {$day} сгенерирована, но пуста.";
        } else {
            $text = "Не удалось сгенерировать сводку за {$day}.";
        }
        $this->tg->sendMessage($chatId, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $messageId);
    }

    private function extractMedia(array $msg): array {
        $r = ['type' => null, 'file_id' => null, 'file_unique_id' => null, 'mime' => null, 'duration_sec' => null];

        if (!empty($msg['voice']) && is_array($msg['voice'])) {
            $r['type']        = 'voice';
            $r['file_id']     = (string)($msg['voice']['file_id'] ?? '');
            $r['file_unique_id'] = (string)($msg['voice']['file_unique_id'] ?? '');
            $r['mime']        = (string)($msg['voice']['mime_type'] ?? 'audio/ogg');
            $r['duration_sec']= isset($msg['voice']['duration']) ? (int)$msg['voice']['duration'] : null;
        } elseif (!empty($msg['audio']) && is_array($msg['audio'])) {
            $r['type']        = 'audio';
            $r['file_id']     = (string)($msg['audio']['file_id'] ?? '');
            $r['file_unique_id'] = (string)($msg['audio']['file_unique_id'] ?? '');
            $r['mime']        = (string)($msg['audio']['mime_type'] ?? 'audio/mpeg');
            $r['duration_sec']= isset($msg['audio']['duration']) ? (int)$msg['audio']['duration'] : null;
        } elseif (!empty($msg['photo']) && is_array($msg['photo'])) {
            $best = null;
            foreach ($msg['photo'] as $p) {
                if (!is_array($p) || empty($p['file_id'])) continue;
                if ($best === null || (int)($p['file_size'] ?? 0) >= (int)($best['file_size'] ?? 0)) $best = $p;
            }
            if ($best) {
                $r['type']           = 'photo';
                $r['file_id']        = (string)($best['file_id'] ?? '');
                $r['file_unique_id'] = (string)($best['file_unique_id'] ?? '');
                $r['mime']           = 'image/jpeg';
            }
        }
        return $r;
    }

    private function extractMediaText(string $fileId, string $mimeType): string {
        $info = $this->tg->getFileUrl($fileId);
        if (!is_array($info) || empty($info['url'])) return '';
        $fileSize = (int)($info['file_size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > 15_000_000) return '';

        $bytes = $this->tg->fetchBytes((string)$info['url'], 25);
        if (!is_string($bytes) || $bytes === '') return '';

        $resp = $this->gemini->generate(
            $this->model,
            [
                ['text' => 'Transcribe audio or extract visible text from the media. Return only JSON.'],
                ['inline_data' => ['mime_type' => $mimeType ?: 'application/octet-stream', 'data' => base64_encode($bytes)]],
            ],
            ['system' => 'Return strict JSON only: {"text":"...","lang":"","confidence":0}', 'temperature' => 0.2, 'maxOutputTokens' => 1000, 'responseMimeType' => 'application/json', 'tag' => 'media_extract']
        );
        $j = $this->gemini->json($resp);
        return is_array($j) ? trim((string)($j['text'] ?? '')) : '';
    }

    private function buildContext(string $chatId): array {
        $rows = $this->msgRepo->fetchRecentByChat($chatId, 30);
        $rows = array_reverse($rows);
        $out  = [];
        foreach ($rows as $r) {
            $from = trim((string)($r['tg_username'] ?? $r['tg_name'] ?? ''));
            $text = trim((string)($r['text'] ?? ''));
            $mt   = trim((string)($r['media_text'] ?? ''));
            $body = trim($text . ($mt !== '' ? "\n[media]\n" . $mt : ''));
            if ($body === '') continue;
            if (mb_strlen($body) > 1000) $body = mb_substr($body, 0, 1000) . '…';
            $out[] = ['from' => $from, 'text' => $body, 'at' => (string)($r['received_at'] ?? '')];
        }
        return $out;
    }

    private function geminiWaitSec(): int {
        $now  = time();
        $b    = strtotime((string)$this->settings->get('gemini_block_until'));
        $n    = strtotime((string)$this->settings->get('gemini_next_allowed_until'));
        $bRem = (is_int($b) && $b > $now) ? $b - $now : 0;
        $nRem = (is_int($n) && $n > $now) ? $n - $now : 0;
        return max($bRem, $nRem);
    }

    private function isGreeting(string $text): bool {
        $t = trim(mb_strtolower($text));
        if ($t === '') return false;
        if (mb_strlen($t) > 40) return false;
        return (bool)preg_match('/^(привет|здравствуй|здравствуйте|добрый\\s+день|добрый\\s+вечер|доброе\\s+утро|hi|hello)\\b/u', $t);
    }
}
