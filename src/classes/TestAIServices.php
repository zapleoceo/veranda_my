<?php

namespace App\Classes;

class TestAIAnnouncementService {
    private TestAIConfig $cfg;
    private TestAIGeminiClient $gemini;
    private TestAIHtmlSanitizer $sanitizer;
    private TestAIDailySummariesRepository $dailyRepo;
    private TestAIRawMessagesRepository $rawRepo;
    private string $cacheDir;

    public function __construct(
        TestAIConfig $cfg,
        TestAIGeminiClient $gemini,
        TestAIHtmlSanitizer $sanitizer,
        TestAIDailySummariesRepository $dailyRepo,
        TestAIRawMessagesRepository $rawRepo,
        string $cacheDir
    ) {
        $this->cfg = $cfg;
        $this->gemini = $gemini;
        $this->sanitizer = $sanitizer;
        $this->dailyRepo = $dailyRepo;
        $this->rawRepo = $rawRepo;
        $this->cacheDir = $cacheDir;
    }

    public function getCached(string $date): string {
        $f = $this->cacheFile($date);
        if (!is_file($f)) return '';
        $html = (string)file_get_contents($f);
        return $html;
    }

    public function generate(string $date): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        $events = $this->collectEventsForAnnounceDate($date);
        $todayMessages = $this->collectTodayMessagesIfToday($date);

        $system = 'Return HTML only. No markdown. No scripts. Use simple tags: div,p,br,strong,em,ul,li,h2,h3,a,span.';
        $prompt = "Create a short HTML announcement for the restaurant for date {$date}. If no information is available, return HTML with a short message that there is no confirmed announcement yet.";
        $payload = [
            'date' => $date,
            'events' => $events,
            'today_messages' => $todayMessages,
        ];

        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [['text' => $prompt], ['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => 0.3, 'maxOutputTokens' => 2200, 'tag' => 'announce_generate']
        );
        $html = $this->gemini->text($resp);
        $html = $this->sanitizer->sanitizeHtml($html);

        if ($html !== '') {
            $this->ensureCacheDir();
            @file_put_contents($this->cacheFile($date), $html, LOCK_EX);
        }

        return $html;
    }

    private function cacheFile(string $date): string {
        return rtrim($this->cacheDir, '/\\') . '/announce_' . $date . '.html';
    }

    private function ensureCacheDir(): void {
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0775, true);
    }

    private function collectEventsForAnnounceDate(string $announceDate): array {
        $since = date('Y-m-d', strtotime('-90 day')) . ' 00:00:00';
        $events = [];
        $rows = $this->dailyRepo->listSince($since);
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $ej = json_decode((string)($r['events_json'] ?? '[]'), true);
            if (!is_array($ej)) continue;
            foreach ($ej as $ev) {
                if (!is_array($ev)) continue;
                if (isset($ev['announce_date']) && (string)$ev['announce_date'] === $announceDate) $events[] = $ev;
            }
        }
        return $events;
    }

    private function collectTodayMessagesIfToday(string $date): array {
        $today = date('Y-m-d');
        if ($date !== $today) return [];

        $from = $today . ' 00:00:00';
        $to = $today . ' 23:59:59';
        $rows = $this->rawRepo->fetchForRange($from, $to);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $txt = trim((string)($r['text'] ?? ''));
            $m = trim((string)($r['media_text'] ?? ''));
            $combined = trim($txt . "\n" . ($m !== '' ? ('[media]' . "\n" . $m) : ''));
            if ($combined === '') continue;
            $out[] = [
                'tg_chat_id' => (string)($r['tg_chat_id'] ?? ''),
                'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
                'received_at' => (string)($r['received_at'] ?? ''),
                'chat_title' => (string)($r['tg_chat_title'] ?? ''),
                'from' => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
                'text' => $combined,
            ];
        }
        return $out;
    }
}

class TestAIDailySummaryService {
    private TestAIConfig $cfg;
    private TestAIGeminiClient $gemini;
    private TestAIRawMessagesRepository $rawRepo;
    private TestAIDailySummariesRepository $dailyRepo;

    public function __construct(
        TestAIConfig $cfg,
        TestAIGeminiClient $gemini,
        TestAIRawMessagesRepository $rawRepo,
        TestAIDailySummariesRepository $dailyRepo
    ) {
        $this->cfg = $cfg;
        $this->gemini = $gemini;
        $this->rawRepo = $rawRepo;
        $this->dailyRepo = $dailyRepo;
    }

    public function runDay(string $day): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return false;

        $from = $day . ' 00:00:00';
        $to = $day . ' 23:59:59';
        $rows = $this->rawRepo->fetchForRange($from, $to);

        $items = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $txt = trim((string)($r['text'] ?? ''));
            $m = trim((string)($r['media_text'] ?? ''));
            $combined = trim($txt . "\n" . ($m !== '' ? ('[media]' . "\n" . $m) : ''));
            if ($combined === '') continue;
            $items[] = [
                'tg_chat_id' => (string)($r['tg_chat_id'] ?? ''),
                'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
                'received_at' => (string)($r['received_at'] ?? ''),
                'chat_title' => (string)($r['tg_chat_title'] ?? ''),
                'from' => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
                'text' => $combined,
            ];
        }

        $system = 'Return strict JSON only with keys: summary_text (string), events (array). Each event: announce_date (YYYY-MM-DD), title, facts (array of strings), confidence (0..100), sources (array of {tg_chat_id,tg_message_id}).';
        $prompt = "Summarize this day chat activity and extract restaurant announcements. Day: {$day}. If no announcements, events must be empty array.";
        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [
                ['text' => $prompt],
                ['text' => json_encode(['day' => $day, 'messages' => $items], JSON_UNESCAPED_UNICODE)],
            ],
            ['system' => $system, 'temperature' => 0.2, 'maxOutputTokens' => 2500, 'responseMimeType' => 'application/json', 'tag' => 'daily_summary']
        );

        $j = $this->gemini->json($resp);
        if (!is_array($j)) return false;

        $summary = trim((string)($j['summary_text'] ?? ''));
        $events = $j['events'] ?? [];
        if (!is_array($events)) $events = [];
        $eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE);
        if ($eventsJson === false) $eventsJson = '[]';

        $this->dailyRepo->upsert($day, $summary, $eventsJson, date('Y-m-d H:i:s'));
        return true;
    }
}

class TestAIWebhookService {
    private TestAIConfig $cfg;
    private TestAIGeminiClient $gemini;
    private TestAITelegramClient $tg;
    private TestAIHtmlSanitizer $sanitizer;
    private TestAIRawMessagesRepository $rawRepo;
    private TestAIDailySummariesRepository $dailyRepo;
    private TestAIDailySummaryService $dailySvc;
    private TestAISettingsRepository $settingsRepo;
    private TestAIKnowledgeService $knowledgeSvc;
    private TestAILogger $log;

    public function __construct(
        TestAIConfig $cfg,
        TestAIGeminiClient $gemini,
        TestAITelegramClient $tg,
        TestAIHtmlSanitizer $sanitizer,
        TestAIRawMessagesRepository $rawRepo,
        TestAIDailySummariesRepository $dailyRepo,
        TestAIDailySummaryService $dailySvc,
        TestAISettingsRepository $settingsRepo,
        TestAIKnowledgeService $knowledgeSvc,
        TestAILogger $log
    ) {
        $this->cfg = $cfg;
        $this->gemini = $gemini;
        $this->tg = $tg;
        $this->sanitizer = $sanitizer;
        $this->rawRepo = $rawRepo;
        $this->dailyRepo = $dailyRepo;
        $this->dailySvc = $dailySvc;
        $this->settingsRepo = $settingsRepo;
        $this->knowledgeSvc = $knowledgeSvc;
        $this->log = $log;
    }

    public function handleUpdate(array $update): void {
        if (!$this->tg->hasToken()) {
            $this->log->error('tg_token_missing');
            return;
        }

        $msg = $this->extractMessage($update);
        if ($msg === null) return;

        $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
        $chatId = isset($chat['id']) ? (string)$chat['id'] : '';
        if ($chatId === '') return;
        if (is_array($this->cfg->allowedChatIds) && !isset($this->cfg->allowedChatIds[$chatId])) return;

        $chatType = (string)($chat['type'] ?? 'unknown');
        $chatTitle = (string)($chat['title'] ?? '');
        $messageId = isset($msg['message_id']) ? (int)$msg['message_id'] : 0;
        $ts = isset($msg['date']) ? (int)$msg['date'] : time();
        $receivedAt = date('Y-m-d H:i:s', $ts);

        $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
        $userId = isset($from['id']) ? (int)$from['id'] : null;
        $username = trim((string)($from['username'] ?? ''));
        $name = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));

        $text = trim((string)($msg['text'] ?? ''));
        $caption = trim((string)($msg['caption'] ?? ''));
        if ($text === '' && $caption !== '') $text = $caption;

        [$needReply, $queryText] = $this->detectNeedReply($chatType, $text);
        $this->log->info('webhook_message', [
            'chat_id' => $chatId,
            'chat_type' => $chatType,
            'message_id' => $messageId,
            'has_text' => $text !== '' ? 1 : 0,
            'need_reply' => $needReply ? 1 : 0,
            'can_call_gemini' => $this->gemini->canCall() ? 1 : 0,
        ]);

        $m = new TestAIMessage();
        $m->chatId = $chatId;
        $m->chatType = $chatType;
        $m->chatTitle = $chatTitle;
        $m->messageId = $messageId;
        $m->userId = $userId;
        $m->username = $username;
        $m->name = $name;
        $m->receivedAt = $receivedAt;
        $m->text = $text;

        $this->fillMedia($m, $msg);
        $m->metaJson = json_encode(['has_media' => $m->mediaType ? 1 : 0], JSON_UNESCAPED_UNICODE) ?: '{}';

        $this->rawRepo->upsert($m);

        $cmdDay = $this->parseSummaryCommand($text);
        if ($cmdDay !== null) {
            $this->handleSummaryCommand($chatId, $messageId, $cmdDay);
            return;
        }

        if ($this->gemini->canCall() && $m->mediaType && $m->mediaFileId && ($needReply || $chatType === 'private')) {
            $mediaText = $this->tryExtractMediaText($m->mediaFileId, $m->mediaMime ?: 'application/octet-stream');
            if ($mediaText !== '') {
                $m->mediaText = $mediaText;
                $this->rawRepo->updateMediaText($chatId, $messageId, $mediaText);
            }
        }

        if (!$needReply || trim($queryText) === '' || !$this->gemini->canCall()) {
            $this->log->info('webhook_skip_reply', [
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'message_id' => $messageId,
                'need_reply' => $needReply ? 1 : 0,
                'query_empty' => trim($queryText) === '' ? 1 : 0,
                'can_call_gemini' => $this->gemini->canCall() ? 1 : 0,
            ]);
            return;
        }

        $now = time();
        $block = $this->settingsRepo->getKey('gemini_block_until');
        $blockUntil = strtotime((string)($block['v'] ?? ''));
        $blockRem = is_int($blockUntil) && $blockUntil > $now ? ($blockUntil - $now) : 0;
        $next = $this->settingsRepo->getKey('gemini_next_allowed_until');
        $nextUntil = strtotime((string)($next['v'] ?? ''));
        $nextRem = is_int($nextUntil) && $nextUntil > $now ? ($nextUntil - $now) : 0;
        $waitSec = max($blockRem, $nextRem);
        if ($waitSec > 0) {
            $this->log->info('gemini_wait', [
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'message_id' => $messageId,
                'block_rem_sec' => $blockRem,
                'next_rem_sec' => $nextRem,
                'wait_sec' => $waitSec,
            ]);
            if ($chatType === 'private' && $waitSec <= 70) {
                sleep((int)$waitSec);
            } else {
                $msg = 'Лимит запросов к AI исчерпан. Попробуйте через ' . (int)ceil($waitSec) . ' сек.';
                $ok = $this->tg->sendMessage($chatId, $msg, null);
                $this->log->info('telegram_send_result', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'ok' => $ok ? 1 : 0,
                ]);
                return;
            }
        }

        $botPrompt = '';
        $p = $this->settingsRepo->getBotPrompt();
        $botPrompt = is_array($p) ? (string)($p['prompt'] ?? '') : '';

        $ctxMsgs = $this->buildContextMessages($chatId);
        $system = trim($botPrompt);
        if ($system !== '') $system .= "\n\n";
        $system .= "You are a Telegram bot assistant. Reply in Telegram-compatible HTML only. No markdown. Allowed tags: b,strong,i,em,u,ins,s,strike,del,code,pre,a,br. Do not use div/p/ul/ol/li/h1-h6 tags. Keep it concise. If knowledge_docs are provided, use them as a primary factual source. If information is missing, do not invent; ask for clarification or suggest contacting staff.";

        $knowledgeDocs = $this->knowledgeSvc->selectForQuestion($queryText, 5);
        $payload = [
            'chat_id' => $chatId,
            'chat_type' => $chatType,
            'chat_title' => $chatTitle,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'name' => $name,
            ],
            'message_id' => $messageId,
            'question' => $queryText,
            'context' => $ctxMsgs,
        ];
        if ($knowledgeDocs) $payload['knowledge_docs'] = $knowledgeDocs;

        $minIntervalSec = 4;
        $this->settingsRepo->setKey('gemini_next_allowed_until', gmdate('c', time() + $minIntervalSec), date('Y-m-d H:i:s'));
        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => 0.35, 'maxOutputTokens' => 1200, 'tag' => 'chat_reply']
        );

        $html = $this->gemini->text($resp);
        $html = $this->sanitizer->sanitizeTelegramHtml($html);
        if ($html === '') $html = $this->fallbackTelegramHtml($resp);

        $err = '';
        if (is_array($resp['error'] ?? null)) $err = (string)($resp['error']['message'] ?? '');
        if ((int)($resp['_http_code'] ?? 0) === 429 && $err !== '') {
            if (preg_match('/retry in\s*([0-9.]+)s/i', $err, $m)) {
                $sec = (float)($m[1] ?? 0);
                if ($sec > 0) {
                    $until = time() + (int)ceil($sec);
                    $this->settingsRepo->setKey('gemini_block_until', gmdate('c', $until), date('Y-m-d H:i:s'));
                    $this->settingsRepo->setKey('gemini_next_allowed_until', gmdate('c', $until), date('Y-m-d H:i:s'));
                }
            }
        }
        $this->log->info('gemini_reply_ready', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'http_code' => (int)($resp['_http_code'] ?? 0),
            'has_error' => $err !== '' ? 1 : 0,
            'html_len' => mb_strlen($html),
        ]);

        $replyTo = $chatType === 'private' ? null : $messageId;
        $ok = $this->tg->sendMessage($chatId, $html, $replyTo);
        $this->log->info('telegram_send_result', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'ok' => $ok ? 1 : 0,
        ]);
    }

    private function extractMessage(array $update): ?array {
        if (!empty($update['message']) && is_array($update['message'])) return $update['message'];
        if (!empty($update['edited_message']) && is_array($update['edited_message'])) return $update['edited_message'];
        if (!empty($update['channel_post']) && is_array($update['channel_post'])) return $update['channel_post'];
        if (!empty($update['edited_channel_post']) && is_array($update['edited_channel_post'])) return $update['edited_channel_post'];
        return null;
    }

    private function detectNeedReply(string $chatType, string $text): array {
        $needReply = false;
        $queryText = $text;
        if ($chatType === 'private') {
            $needReply = trim($queryText) !== '';
        } else {
            if (preg_match('/^\/(?:ai|ask)(?:@\w+)?\s+([\s\S]+)$/u', $queryText, $m)) {
                $needReply = true;
                $queryText = trim((string)($m[1] ?? ''));
            }
        }
        return [$needReply, $queryText];
    }

    private function fillMedia(TestAIMessage $m, array $msg): void {
        $m->mediaType = null;
        $m->mediaFileId = null;
        $m->mediaFileUniqueId = null;
        $m->mediaMime = null;
        $m->mediaDurationSec = null;

        if (!empty($msg['voice']) && is_array($msg['voice'])) {
            $m->mediaType = 'voice';
            $m->mediaFileId = (string)($msg['voice']['file_id'] ?? '');
            $m->mediaFileUniqueId = (string)($msg['voice']['file_unique_id'] ?? '');
            $m->mediaMime = (string)($msg['voice']['mime_type'] ?? 'audio/ogg');
            $m->mediaDurationSec = isset($msg['voice']['duration']) ? (int)$msg['voice']['duration'] : null;
            return;
        }
        if (!empty($msg['audio']) && is_array($msg['audio'])) {
            $m->mediaType = 'audio';
            $m->mediaFileId = (string)($msg['audio']['file_id'] ?? '');
            $m->mediaFileUniqueId = (string)($msg['audio']['file_unique_id'] ?? '');
            $m->mediaMime = (string)($msg['audio']['mime_type'] ?? 'audio/mpeg');
            $m->mediaDurationSec = isset($msg['audio']['duration']) ? (int)$msg['audio']['duration'] : null;
            return;
        }
        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            $m->mediaType = 'photo';
            $best = null;
            foreach ($msg['photo'] as $p) {
                if (!is_array($p) || empty($p['file_id'])) continue;
                if ($best === null) { $best = $p; continue; }
                $a = (int)($p['file_size'] ?? 0);
                $b = (int)($best['file_size'] ?? 0);
                if ($a >= $b) $best = $p;
            }
            if (is_array($best)) {
                $m->mediaFileId = (string)($best['file_id'] ?? '');
                $m->mediaFileUniqueId = (string)($best['file_unique_id'] ?? '');
                $m->mediaMime = 'image/jpeg';
            }
        }
    }

    private function tryExtractMediaText(string $fileId, string $mimeType): string {
        $info = $this->tg->getFileUrl($fileId);
        $fileSize = is_array($info) ? (int)($info['file_size'] ?? 0) : 0;
        if (!is_array($info) || empty($info['url']) || $fileSize <= 0 || $fileSize > 15000000) return '';

        $bytes = $this->tg->fetchBytes((string)$info['url'], 25);
        if (!is_string($bytes) || $bytes === '') return '';

        $b64 = base64_encode($bytes);
        $system = 'Return strict JSON only: {"text":"...","lang":"","confidence":0}';
        $prompt = 'Transcribe audio or extract visible text from the media. Return only JSON.';
        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mimeType ?: 'application/octet-stream', 'data' => $b64]],
            ],
            ['system' => $system, 'temperature' => 0.2, 'maxOutputTokens' => 1000, 'responseMimeType' => 'application/json', 'tag' => 'media_extract']
        );
        $j = $this->gemini->json($resp);
        if (!is_array($j) || !isset($j['text'])) return '';
        return trim((string)$j['text']);
    }

    private function buildContextMessages(string $chatId): array {
        $ctxMsgs = [];
        $rows = $this->rawRepo->fetchRecentByChat($chatId, 30);
        $rows = array_reverse(is_array($rows) ? $rows : []);
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $u = trim((string)($r['tg_username'] ?? ''));
            if ($u === '') $u = trim((string)($r['tg_name'] ?? ''));
            $t = trim((string)($r['text'] ?? ''));
            $mt = trim((string)($r['media_text'] ?? ''));
            $combined = trim($t . "\n" . ($mt !== '' ? ('[media]' . "\n" . $mt) : ''));
            if ($combined === '') continue;
            if (mb_strlen($combined) > 1200) $combined = mb_substr($combined, 0, 1200) . '…';
            $ctxMsgs[] = [
                'at' => (string)($r['received_at'] ?? ''),
                'from' => $u,
                'text' => $combined,
            ];
        }
        return $ctxMsgs;
    }

    private function fallbackTelegramHtml(array $resp): string {
        $err = '';
        if (is_array($resp['error'] ?? null)) $err = trim((string)($resp['error']['message'] ?? ''));
        if ($err !== '' && preg_match('/quota exceeded|exceeded your current quota|rate limit/i', $err)) {
            $retry = '';
            if (preg_match('/retry in\s+([0-9.]+)s/i', $err, $m)) {
                $sec = (float)($m[1] ?? 0);
                if ($sec > 0) $retry = ' Попробуйте через ' . (int)ceil($sec) . ' сек.';
            }
            return 'Лимит запросов к AI исчерпан.' . $retry;
        }
        $plain = $this->gemini->text($resp);
        $plain = trim(strip_tags($plain));
        if ($plain !== '') {
            if (mb_strlen($plain) > 3500) $plain = mb_substr($plain, 0, 3500) . '…';
            return htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($err !== '') {
            if (mb_strlen($err) > 900) $err = mb_substr($err, 0, 900) . '…';
            return 'Gemini error: ' . htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $code = (int)($resp['_http_code'] ?? 0);
        return $code ? ("Gemini пустой ответ (HTTP {$code}).") : 'Не получилось сформировать ответ.';
    }

    private function parseSummaryCommand(string $text): ?string {
        $t = trim($text);
        if ($t === '') return null;
        if (preg_match('/^\/summary(?:@\w+)?(?:\s+(\d{4}-\d{2}-\d{2}))?\s*$/u', $t, $m)) {
            $day = trim((string)($m[1] ?? ''));
            if ($day === '') $day = date('Y-m-d');
            return $day;
        }
        return null;
    }

    private function handleSummaryCommand(string $chatId, int $messageId, string $day): void {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');

        $now = time();
        $block = $this->settingsRepo->getKey('gemini_block_until');
        $blockUntil = strtotime((string)($block['v'] ?? ''));
        $blockRem = is_int($blockUntil) && $blockUntil > $now ? ($blockUntil - $now) : 0;
        $next = $this->settingsRepo->getKey('gemini_next_allowed_until');
        $nextUntil = strtotime((string)($next['v'] ?? ''));
        $nextRem = is_int($nextUntil) && $nextUntil > $now ? ($nextUntil - $now) : 0;
        $waitSec = max($blockRem, $nextRem);

        $row = $this->dailyRepo->getByDay($day);
        if ($row === null) {
            if ($waitSec > 0) {
                $msg = 'Лимит запросов к AI исчерпан. Попробуйте через ' . (int)ceil($waitSec) . ' сек.';
                $ok = $this->tg->sendMessage($chatId, $msg, null);
                $this->log->info('telegram_send_result', ['chat_id' => $chatId, 'message_id' => $messageId, 'ok' => $ok ? 1 : 0]);
                return;
            }
            $okRun = $this->dailySvc->runDay($day);
            $this->log->info('daily_summary_run', ['day' => $day, 'ok' => $okRun ? 1 : 0, 'via' => 'tg_summary']);
            $row = $this->dailyRepo->getByDay($day);
        }

        $html = $this->formatDailySummaryHtml($day, $row);
        $ok = $this->tg->sendMessage($chatId, $html, null);
        $this->log->info('telegram_send_result', ['chat_id' => $chatId, 'message_id' => $messageId, 'ok' => $ok ? 1 : 0]);
    }

    private function formatDailySummaryHtml(string $day, ?array $row): string {
        $summary = '';
        $events = [];
        $createdAt = '';
        if (is_array($row)) {
            $summary = trim((string)($row['summary_text'] ?? ''));
            $createdAt = (string)($row['created_at'] ?? '');
            $eventsJson = (string)($row['events_json'] ?? '[]');
            $decoded = json_decode($eventsJson, true);
            if (is_array($decoded)) $events = $decoded;
        }

        $out = '<b>Сводка за ' . htmlspecialchars($day, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        if ($createdAt !== '') $out .= '<br><i>Обновлено: ' . htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i>';
        $out .= '<br><br>';

        if ($summary !== '') {
            $out .= htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            $out .= 'Пока нет сохранённой сводки за этот день.';
        }

        $items = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $title = trim((string)($ev['title'] ?? ''));
            if ($title === '') continue;
            $ad = trim((string)($ev['announce_date'] ?? ''));
            $line = '— ' . $title;
            if ($ad !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) $line .= ' (' . $ad . ')';
            $items[] = $line;
            if (count($items) >= 12) break;
        }

        if ($items) {
            $out .= '<br><br><b>События:</b><br>' . htmlspecialchars(implode("\n", $items), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out = str_replace("\n", "<br>", $out);
        }

        if (mb_strlen($out) > 3800) $out = mb_substr($out, 0, 3800) . '…';
        return $out;
    }
}

class TestAIKnowledgeService {
    private TestAIKnowledgeRepository $kb;

    public function __construct(TestAIKnowledgeRepository $kb) {
        $this->kb = $kb;
    }

    public function selectForQuestion(string $question, int $limit = 5): array {
        $limit = max(1, min(8, $limit));
        $q = trim(mb_strtolower($question));
        if ($q === '') return [];

        $keywords = $this->extractKeywords($q);
        if (!$keywords) return [];

        $rows = $this->kb->searchActiveByKeywords($keywords, $limit);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $title = trim((string)($r['title'] ?? ''));
            $content = trim((string)($r['content'] ?? ''));
            if ($content === '') continue;
            if (mb_strlen($content) > 1500) $content = mb_substr($content, 0, 1500) . '…';
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'title' => $title,
                'source_url' => (string)($r['source_url'] ?? ''),
                'content' => $content,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function extractKeywords(string $q): array {
        $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q);
        $q = is_string($q) ? $q : '';
        $parts = preg_split('/\s+/u', trim($q));
        $out = [];
        foreach (is_array($parts) ? $parts : [] as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            if (mb_strlen($p) < 3) continue;
            if (in_array($p, ['какие', 'какой', 'какая', 'какое', 'есть', 'у', 'вас', 'в', 'на', 'и', 'или', 'что', 'это', 'нет', 'там', 'по', 'меню', 'цена', 'сколько', 'стоит', 'пожалуйста', 'спасибо'], true)) continue;
            $out[$p] = true;
            if (count($out) >= 10) break;
        }
        return array_keys($out);
    }
}
