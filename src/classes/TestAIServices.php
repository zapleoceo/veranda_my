<?php

namespace App\Classes;

class TestAIAnnouncementService {
    private TestAIConfig $cfg;
    private TestAIGeminiClient $gemini;
    private TestAIHtmlSanitizer $sanitizer;
    private TestAIDailySummariesRepository $dailyRepo;
    private TestAIRawMessagesRepository $rawRepo;
    private TestAISettingsRepository $settingsRepo;
    private string $cacheDir;

    public function __construct(
        TestAIConfig $cfg,
        TestAIGeminiClient $gemini,
        TestAIHtmlSanitizer $sanitizer,
        TestAIDailySummariesRepository $dailyRepo,
        TestAIRawMessagesRepository $rawRepo,
        TestAISettingsRepository $settingsRepo,
        string $cacheDir
    ) {
        $this->cfg = $cfg;
        $this->gemini = $gemini;
        $this->sanitizer = $sanitizer;
        $this->dailyRepo = $dailyRepo;
        $this->rawRepo = $rawRepo;
        $this->settingsRepo = $settingsRepo;
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

        $map = $this->loadInstrMap();
        $lang = $this->resolveLang('bot_lang_announce', json_encode([$events, $todayMessages], JSON_UNESCAPED_UNICODE) ?: '');
        $common = trim((string)($this->settingsRepo->getBotPrompt()['prompt'] ?? ''));
        $base = trim((string)($this->settingsRepo->getKey('bot_system_base')['v'] ?? ''));
        $announceSys = trim((string)($this->settingsRepo->getKey('bot_system_announce')['v'] ?? ''));
        $parts = [];
        if ($this->isOn($map, 'announce', 'common_prompt') && $common !== '') $parts[] = $common;
        if ($this->isOn($map, 'announce', 'system_base') && $base !== '') $parts[] = $base;
        if ($this->isOn($map, 'announce', 'system_announce') && $announceSys !== '') $parts[] = $announceSys;
        if (!$parts && $announceSys !== '') $parts[] = $announceSys;
        $system = trim(implode("\n\n", $parts));
        if ($system !== '') $system .= "\n\n";
        $system .= "Write the announcement in " . strtoupper($lang) . ".";
        $prompt = $lang === 'ru'
            ? "Сформируй короткий HTML-анонс для ресторана на дату {$date}. Если информации нет — верни HTML с коротким сообщением, что подтверждённого анонса пока нет."
            : "Create a short HTML announcement for the restaurant for date {$date}. If no information is available, return HTML with a short message that there is no confirmed announcement yet.";
        $payload = [
            'date' => $date,
            'lang' => $lang,
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
        if ($lang === 'ru' && $this->latinCount(strip_tags($html)) >= 40) {
            $system2 = $system . "\n\nRewrite: output must be in RU.";
            $resp2 = $this->gemini->generate(
                $this->cfg->geminiModel,
                [['text' => $prompt], ['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
                ['system' => $system2, 'temperature' => 0.2, 'maxOutputTokens' => 2200, 'tag' => 'announce_generate_ru_fix']
            );
            $html2 = $this->gemini->text($resp2);
            $html2 = $this->sanitizer->sanitizeHtml($html2);
            if ($html2 !== '') $html = $html2;
        }

        if ($html !== '') {
            $this->ensureCacheDir();
            @file_put_contents($this->cacheFile($date), $html, LOCK_EX);
        }

        return $html;
    }

    private function resolveLang(string $key, string $text): string {
        $row = $this->settingsRepo->getKey($key);
        $mode = strtolower(trim((string)($row['v'] ?? 'auto')));
        if (in_array($mode, ['ru', 'en'], true)) return $mode;
        return $this->detectLang($text);
    }

    private function detectLang(string $text): string {
        if (preg_match('/\p{Cyrillic}/u', $text)) return 'ru';
        if (preg_match('/[A-Za-z]/', $text)) return 'en';
        return 'ru';
    }

    private function latinCount(string $s): int {
        if ($s === '') return 0;
        if (!preg_match_all('/[A-Za-z]/', $s, $m)) return 0;
        return is_array($m[0] ?? null) ? count($m[0]) : 0;
    }

    private function loadInstrMap(): array {
        $row = $this->settingsRepo->getKey('bot_instr_map');
        $decoded = json_decode((string)($row['v'] ?? ''), true);
        if (!is_array($decoded)) $decoded = [];
        $fallback = [
            'chat' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 1, 'system_daily' => 0, 'system_announce' => 0],
            'daily' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 1, 'system_announce' => 0],
            'announce' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 0, 'system_announce' => 1],
        ];
        foreach ($fallback as $mode => $blocks) {
            if (!is_array($decoded[$mode] ?? null)) $decoded[$mode] = [];
            foreach ($blocks as $k => $v) {
                $decoded[$mode][$k] = !empty($decoded[$mode][$k]) ? 1 : 0;
            }
        }
        return $decoded;
    }

    private function isOn(array $map, string $mode, string $block): bool {
        return !empty($map[$mode][$block]);
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
    private TestAISettingsRepository $settingsRepo;

    public function __construct(
        TestAIConfig $cfg,
        TestAIGeminiClient $gemini,
        TestAIRawMessagesRepository $rawRepo,
        TestAIDailySummariesRepository $dailyRepo,
        TestAISettingsRepository $settingsRepo
    ) {
        $this->cfg = $cfg;
        $this->gemini = $gemini;
        $this->rawRepo = $rawRepo;
        $this->dailyRepo = $dailyRepo;
        $this->settingsRepo = $settingsRepo;
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

        $map = $this->loadInstrMap();
        $lang = $this->resolveLang('bot_lang_daily', json_encode($items, JSON_UNESCAPED_UNICODE) ?: '');
        $common = trim((string)($this->settingsRepo->getBotPrompt()['prompt'] ?? ''));
        $base = trim((string)($this->settingsRepo->getKey('bot_system_base')['v'] ?? ''));
        $dailySys = trim((string)($this->settingsRepo->getKey('bot_system_daily')['v'] ?? ''));
        $parts = [];
        if ($this->isOn($map, 'daily', 'common_prompt') && $common !== '') $parts[] = $common;
        if ($this->isOn($map, 'daily', 'system_base') && $base !== '') $parts[] = $base;
        if ($this->isOn($map, 'daily', 'system_daily') && $dailySys !== '') $parts[] = $dailySys;
        if (!$parts && $dailySys !== '') $parts[] = $dailySys;
        $system = trim(implode("\n\n", $parts));
        if ($system !== '') $system .= "\n\n";
        $system .= "All string fields must be written in " . strtoupper($lang) . ".";
        $prompt = $lang === 'ru'
            ? "Сделай сводку активности чата за день и извлеки анонсы ресторана. День: {$day}. Если анонсов нет — events должен быть пустым массивом."
            : "Summarize this day chat activity and extract restaurant announcements. Day: {$day}. If no announcements, events must be empty array.";
        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [
                ['text' => $prompt],
                ['text' => json_encode(['day' => $day, 'lang' => $lang, 'messages' => $items], JSON_UNESCAPED_UNICODE)],
            ],
            ['system' => $system, 'temperature' => 0.2, 'maxOutputTokens' => 2500, 'responseMimeType' => 'application/json', 'tag' => 'daily_summary']
        );

        $j = $this->gemini->json($resp);
        if (!is_array($j)) return false;

        if ($lang === 'ru' && $this->needsRuRewrite($j)) {
            $system2 = $system . "\n\nRewrite: translate all string fields to RU. Keep the same JSON schema.";
            $resp2 = $this->gemini->generate(
                $this->cfg->geminiModel,
                [['text' => json_encode(['lang' => 'ru', 'data' => $j], JSON_UNESCAPED_UNICODE)]],
                ['system' => $system2, 'temperature' => 0.1, 'maxOutputTokens' => 2500, 'responseMimeType' => 'application/json', 'tag' => 'daily_summary_ru_fix']
            );
            $j2 = $this->gemini->json($resp2);
            if (is_array($j2)) $j = $j2;
        }

        $summary = trim((string)($j['summary_text'] ?? ''));
        $events = $j['events'] ?? [];
        if (!is_array($events)) $events = [];
        $eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE);
        if ($eventsJson === false) $eventsJson = '[]';

        $this->dailyRepo->upsert($day, $summary, $eventsJson, date('Y-m-d H:i:s'));
        return true;
    }

    private function resolveLang(string $key, string $text): string {
        $row = $this->settingsRepo->getKey($key);
        $mode = strtolower(trim((string)($row['v'] ?? 'auto')));
        if (in_array($mode, ['ru', 'en'], true)) return $mode;
        return $this->detectLang($text);
    }

    private function detectLang(string $text): string {
        if (preg_match('/\p{Cyrillic}/u', $text)) return 'ru';
        if (preg_match('/[A-Za-z]/', $text)) return 'en';
        return 'ru';
    }

    private function needsRuRewrite(array $j): bool {
        $t = (string)($j['summary_text'] ?? '');
        if ($this->latinCount($t) >= 20) return true;
        $events = $j['events'] ?? [];
        if (is_array($events)) {
            foreach ($events as $ev) {
                if (!is_array($ev)) continue;
                $title = (string)($ev['title'] ?? '');
                if ($this->latinCount($title) >= 12) return true;
            }
        }
        return false;
    }

    private function latinCount(string $s): int {
        if ($s === '') return 0;
        if (!preg_match_all('/[A-Za-z]/', $s, $m)) return 0;
        return is_array($m[0] ?? null) ? count($m[0]) : 0;
    }

    private function loadInstrMap(): array {
        $row = $this->settingsRepo->getKey('bot_instr_map');
        $decoded = json_decode((string)($row['v'] ?? ''), true);
        if (!is_array($decoded)) $decoded = [];
        $fallback = [
            'chat' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 1, 'system_daily' => 0, 'system_announce' => 0],
            'daily' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 1, 'system_announce' => 0],
            'announce' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 0, 'system_announce' => 1],
        ];
        foreach ($fallback as $mode => $blocks) {
            if (!is_array($decoded[$mode] ?? null)) $decoded[$mode] = [];
            foreach ($blocks as $k => $v) {
                $decoded[$mode][$k] = !empty($decoded[$mode][$k]) ? 1 : 0;
            }
        }
        return $decoded;
    }

    private function isOn(array $map, string $mode, string $block): bool {
        return !empty($map[$mode][$block]);
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
    private TestAIMenuService $menuSvc;
    private TestAIChatAgentService $agentSvc;
    private TestAILogger $log;
    private ?array $behaviorCache;

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
        TestAIMenuService $menuSvc,
        TestAIChatAgentService $agentSvc,
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
        $this->menuSvc = $menuSvc;
        $this->agentSvc = $agentSvc;
        $this->log = $log;
        $this->behaviorCache = null;
    }

    public function handleUpdate(array $update): void {
        if (!$this->tg->hasToken()) {
            $this->log->error('tg_token_missing');
            return;
        }

        $msg = $this->extractMessage($update);
        if ($msg === null) return;

        $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
        $chatType = (string)($chat['type'] ?? 'unknown');
        $chatId = isset($chat['id']) ? (string)$chat['id'] : '';
        if ($chatId === '') return;
        if ($chatType !== 'private' && is_array($this->cfg->allowedChatIds) && !isset($this->cfg->allowedChatIds[$chatId])) return;
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
        $cmdDay = $this->parseSummaryCommand($text);
        $this->log->info('webhook_message', [
            'chat_id' => $chatId,
            'chat_type' => $chatType,
            'message_id' => $messageId,
            'has_text' => $text !== '' ? 1 : 0,
            'text_head' => mb_substr($text, 0, 120),
            'need_reply' => $needReply ? 1 : 0,
            'is_summary' => $cmdDay !== null ? 1 : 0,
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

        if ($cmdDay !== null) {
            $this->log->info('summary_command', ['chat_id' => $chatId, 'message_id' => $messageId, 'day' => $cmdDay]);
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
        $map = $this->loadInstrMap();
        $lang = $this->resolveLang('bot_lang_chat', $queryText, $ctxMsgs);
        $common = trim($botPrompt);
        $baseSys = trim((string)($this->settingsRepo->getKey('bot_system_base')['v'] ?? ''));
        $chatSys = trim((string)($this->settingsRepo->getKey('bot_system_chat')['v'] ?? ''));
        $parts = [];
        if ($this->isOn($map, 'chat', 'common_prompt') && $common !== '') $parts[] = $common;
        if ($this->isOn($map, 'chat', 'system_base') && $baseSys !== '') $parts[] = $baseSys;
        if ($this->isOn($map, 'chat', 'system_chat') && $chatSys !== '') $parts[] = $chatSys;
        if (!$parts && $chatSys !== '') $parts[] = $chatSys;
        $system = trim(implode("\n\n", $parts));
        if ($system !== '') $system .= "\n\n";
        $system .= "Reply in " . strtoupper($lang) . ". If the user asks in a different language, prefer the user's language.";
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
            'lang' => $lang,
            'question' => $queryText,
            'context' => $ctxMsgs,
        ];
        $agent = $this->agentSvc->answerChat($system, $payload);
        $html = $this->sanitizer->sanitizeTelegramHtml((string)($agent['html'] ?? ''));
        if ($html === '') $html = 'Не получилось сформировать ответ.';

        $minIntervalSec = 4;
        $this->settingsRepo->setKey('gemini_next_allowed_until', gmdate('c', time() + $minIntervalSec), date('Y-m-d H:i:s'));

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

    private function resolveLang(string $key, string $question, array $ctxMsgs): string {
        $row = $this->settingsRepo->getKey($key);
        $mode = strtolower(trim((string)($row['v'] ?? 'auto')));
        if (in_array($mode, ['ru', 'en'], true)) return $mode;
        $qLang = $this->detectLang($question);
        if ($qLang !== '') return $qLang;
        $ctxText = '';
        foreach ($ctxMsgs as $m) {
            if (!is_array($m)) continue;
            $ctxText .= "\n" . (string)($m['text'] ?? '');
            if (mb_strlen($ctxText) > 1200) break;
        }
        return $this->detectLang($ctxText);
    }

    private function detectLang(string $text): string {
        $t = trim((string)$text);
        if ($t === '') return '';
        if (preg_match('/\p{Cyrillic}/u', $t)) return 'ru';
        if (preg_match('/[A-Za-z]/', $t)) return 'en';
        return '';
    }

    private function isMenuQuestion(string $q, array $beh): bool {
        $t = mb_strtolower(trim((string)$q));
        if ($t === '') return false;
        $det = is_array($beh['detectors'] ?? null) ? $beh['detectors'] : [];
        $kw = $this->normalizeStrList($det['menu_keywords'] ?? []);
        return $this->matchesAny($t, $kw);
    }

    private function isAnnouncementQuestion(string $q, array $beh): bool {
        $t = mb_strtolower(trim((string)$q));
        if ($t === '') return false;
        $det = is_array($beh['detectors'] ?? null) ? $beh['detectors'] : [];
        $kw = $this->normalizeStrList($det['announce_keywords'] ?? []);
        return $this->matchesAny($t, $kw);
    }

    private function isKbCheckRequest(string $q, array $kbCfg): bool {
        $t = mb_strtolower(trim((string)$q));
        if ($t === '') return false;
        $tr = $this->normalizeStrList($kbCfg['check_triggers'] ?? []);
        return $this->matchesAny($t, $tr);
    }

    private function lastQuestionFromContext(array $ctxMsgs, array $kbCfg): string {
        for ($i = count($ctxMsgs) - 1; $i >= 0; $i--) {
            $m = $ctxMsgs[$i] ?? null;
            if (!is_array($m)) continue;
            $t = trim((string)($m['text'] ?? ''));
            if ($t === '') continue;
            if ($this->isKbCheckRequest($t, $kbCfg)) continue;
            if (preg_match('/^\/\w+/u', $t)) continue;
            if (mb_strlen($t) < 4) continue;
            return $t;
        }
        return '';
    }

    private function loadBehavior(): array {
        if (is_array($this->behaviorCache)) return $this->behaviorCache;
        $row = $this->settingsRepo->getKey('bot_behavior_json');
        $decoded = json_decode((string)($row['v'] ?? ''), true);
        $this->behaviorCache = is_array($decoded) ? $decoded : [];
        return $this->behaviorCache;
    }

    private function normalizeStrList($v): array {
        $out = [];
        if (!is_array($v)) return [];
        foreach ($v as $it) {
            $s = mb_strtolower(trim((string)$it));
            if ($s === '') continue;
            $out[] = $s;
        }
        return array_values(array_unique($out));
    }

    private function matchesAny(string $haystackLower, array $needlesLower): bool {
        foreach ($needlesLower as $n) {
            if ($n === '') continue;
            if (mb_stripos($haystackLower, $n) !== false) return true;
        }
        return false;
    }

    private function loadInstrMap(): array {
        $row = $this->settingsRepo->getKey('bot_instr_map');
        $decoded = json_decode((string)($row['v'] ?? ''), true);
        if (!is_array($decoded)) $decoded = [];
        $fallback = [
            'chat' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 1, 'system_daily' => 0, 'system_announce' => 0],
            'daily' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 1, 'system_announce' => 0],
            'announce' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 0, 'system_announce' => 1],
        ];
        foreach ($fallback as $mode => $blocks) {
            if (!is_array($decoded[$mode] ?? null)) $decoded[$mode] = [];
            foreach ($blocks as $k => $v) {
                $decoded[$mode][$k] = !empty($decoded[$mode][$k]) ? 1 : 0;
            }
        }
        return $decoded;
    }

    private function isOn(array $map, string $mode, string $block): bool {
        return !empty($map[$mode][$block]);
    }

    private function parseSummaryCommand(string $text): ?string {
        $t = trim($text);
        if ($t === '') return null;
        if (!preg_match('/^\/summary(?:@\w+)?(?:\s+([0-9]{4}(?:-[0-9]{1,2}){0,2}))?\s*$/u', $t, $m)) return null;
        $raw = trim((string)($m[1] ?? ''));
        if ($raw === '') return date('Y-m-d');
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $mm)) {
            $y = (int)$mm[1];
            $mo = (int)$mm[2];
            $d = (int)$mm[3];
            if (!checkdate($mo, $d, $y)) return date('Y-m-d');
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        if (preg_match('/^\d{4}-\d{1,2}$/', $raw)) return date('Y-m-d');
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
        $langRow = $this->settingsRepo->getKey('bot_lang_daily');
        $langMode = strtolower(trim((string)($langRow['v'] ?? 'ru')));
        $wantRu = $langMode === 'ru';
        $hasLatin = false;
        if ($wantRu && is_array($row)) {
            $t = (string)($row['summary_text'] ?? '');
            $hasLatin = preg_match('/[A-Za-z]/', $t) ? true : false;
        }
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
        } elseif ($hasLatin && $waitSec <= 0 && $this->gemini->canCall()) {
            $okRun = $this->dailySvc->runDay($day);
            $this->log->info('daily_summary_rerun_lang_fix', ['day' => $day, 'ok' => $okRun ? 1 : 0, 'via' => 'tg_summary']);
            $row = $this->dailyRepo->getByDay($day);
        }

        $html = $this->formatDailySummaryHtml($day, $row);
        $html = $this->sanitizer->sanitizeTelegramHtml($html);
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
        if ($createdAt !== '') $out .= "\n" . '<i>Обновлено: ' . htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i>';
        $out .= "\n\n";

        if ($summary !== '') {
            $out .= htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            $out .= 'Пока нет сохранённой сводки за этот день.';
        }

        $items = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $title = trim((string)($ev['title'] ?? ''));
            $title = html_entity_decode($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = trim(strip_tags($title));
            if ($title === '') continue;
            $ad = trim((string)($ev['announce_date'] ?? ''));
            $line = '— ' . $title;
            if ($ad !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) $line .= ' (' . $ad . ')';
            $items[] = $line;
            if (count($items) >= 12) break;
        }

        if ($items) {
            $out .= "\n\n" . '<b>События:</b>' . "\n" . htmlspecialchars(implode("\n", $items), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (mb_strlen($out) > 3800) $out = mb_substr($out, 0, 3800) . '…';
        return $out;
    }
}

class TestAIKnowledgeService {
    private TestAIConfig $cfg;
    private TestAIKnowledgeRepository $kb;
    private TestAISettingsRepository $settingsRepo;
    private ?TestAILogger $log;

    public function __construct(TestAIConfig $cfg, TestAIKnowledgeRepository $kb, TestAISettingsRepository $settingsRepo, ?TestAILogger $log = null) {
        $this->cfg = $cfg;
        $this->kb = $kb;
        $this->settingsRepo = $settingsRepo;
        $this->log = $log;
    }

    public function selectForQuestion(string $question, int $limit = 5): array {
        $limit = max(1, min(8, $limit));
        $q = trim(mb_strtolower($question));
        if ($q === '') return [];

        $beh = $this->loadBehavior();
        $kbCfg = is_array($beh['kb'] ?? null) ? $beh['kb'] : [];
        $liveEnable = !empty($kbCfg['live_fetch_enable']);
        $liveMaxDocs = (int)($kbCfg['live_fetch_max_docs'] ?? 2);
        $liveMaxDocs = max(0, min(6, $liveMaxDocs));
        $liveMaxLen = (int)($kbCfg['live_fetch_max_len'] ?? 60000);
        $liveMaxLen = max(500, min(60000, $liveMaxLen));

        $keywords = $this->extractKeywords($q);
        if (!$keywords) return [];

        $rows = $this->kb->searchActiveByKeywords($keywords, $limit);
        $out = [];
        $liveFetched = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $title = trim((string)($r['title'] ?? ''));
            $content = trim((string)($r['content'] ?? ''));
            $sourceUrl = trim((string)($r['source_url'] ?? ''));
            $liveOk = false;
            if ($liveEnable && $content === '' && $sourceUrl !== '' && $liveMaxDocs > 0 && $liveFetched < $liveMaxDocs) {
                $live = $this->tryFetchLiveContent($sourceUrl, $liveMaxLen);
                if ($live !== '') {
                    $content = $live;
                    $liveFetched++;
                    $liveOk = true;
                }
            }
            if ($content === '') continue;
            if (mb_strlen($content) > 1500) $content = mb_substr($content, 0, 1500) . '…';
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'title' => $title,
                'source_url' => $sourceUrl,
                'content' => $content,
                'live_fetched' => $liveOk ? 1 : 0,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    public function fetchLiveTextForUrl(string $url, int $maxLen = 3000): string {
        return $this->tryFetchLiveContent($url, $maxLen);
    }

    private function tryFetchLiveContent(string $url, int $maxLen): string {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        if (!$this->isAllowedUrl($url)) return '';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 7);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: veranda-ai-bot/1.0', 'Accept: text/html,application/json;q=0.9,text/plain;q=0.8,*/*;q=0.1']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) {
            if ($this->log) $this->log->info('live_source_fetch_failed', ['url' => $url, 'http_code' => $code]);
            return '';
        }

        if (strlen($body) > 350000) $body = substr($body, 0, 350000);
        $ctLow = strtolower($ct);
        if (strpos($ctLow, 'application/json') !== false || (strlen(ltrim($body)) > 0 && ltrim($body)[0] === '{')) {
            $j = json_decode($body, true);
            if (is_array($j)) {
                $pretty = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return is_string($pretty) ? trim($pretty) : '';
            }
        }

        return $this->htmlToText($body, $maxLen);
    }

    private function isAllowedUrl(string $url): bool {
        $u = @parse_url($url);
        $host = is_array($u) ? strtolower((string)($u['host'] ?? '')) : '';
        if ($host === '') return false;

        $cfgHost = '';
        if ($this->cfg->appUrl !== '') {
            $uu = @parse_url($this->cfg->appUrl);
            $cfgHost = is_array($uu) ? strtolower((string)($uu['host'] ?? '')) : '';
        }
        $allowed = [];
        foreach ([$cfgHost, 'veranda.my', 'www.veranda.my'] as $h) {
            $h = trim((string)$h);
            if ($h !== '') $allowed[$h] = true;
        }
        return isset($allowed[$host]);
    }

    private function htmlToText(string $html, int $maxLen = 3000): string {
        $t = $html;
        $t = preg_replace('/<\s*(script|style|noscript)[^>]*>[\s\S]*?<\s*\/\s*\\1\s*>/i', '', $t);
        $t = preg_replace('/<\s*(br|br\/)\s*>/i', "\n", $t);
        $t = preg_replace('/<\s*\/?\s*(p|div|li|tr|h[1-6])[^>]*>/i', "\n", $t);
        $t = strip_tags((string)$t);
        $t = html_entity_decode((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', (string)$t);
        $t = preg_replace("/\n{3,}/", "\n\n", (string)$t);
        $t = trim((string)$t);
        $maxLen = max(500, min(60000, $maxLen));
        if (mb_strlen($t) > $maxLen) $t = mb_substr($t, 0, $maxLen) . '…';
        return $t;
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
            if (in_array($p, ['какие', 'какой', 'какая', 'какое', 'есть', 'у', 'вас', 'в', 'на', 'и', 'или', 'что', 'это', 'нет', 'там', 'по', 'сколько', 'пожалуйста', 'спасибо'], true)) continue;
            $out[$p] = true;
            if (count($out) >= 10) break;
        }

        $qq = (string)$q;
        if (preg_match('/\b(меню|блюд|блюда|позици|ассортимент)\b/u', $qq)) $out['меню'] = true;
        if (preg_match('/\b(завтрак|завтраки|breakfast)\b/u', $qq)) { $out['завтрак'] = true; $out['меню'] = true; }
        if (preg_match('/\b(бар|пиво|вино|коктейл)\b/u', $qq)) { $out['бар'] = true; $out['меню'] = true; }
        if (preg_match('/\b(цена|цен|сто(ит|ят)|сколько)\b/u', $qq)) { $out['цена'] = true; $out['меню'] = true; }
        if (preg_match('/\b(анонс|афиш|событи|мероприят|концерт|музык|live|dj|дуэт|duo|bibi)\b/u', $qq)) { $out['анонс'] = true; $out['афиша'] = true; }

        return array_keys($out);
    }

    private function loadBehavior(): array {
        $row = $this->settingsRepo->getKey('bot_behavior_json');
        $decoded = json_decode((string)($row['v'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}

class TestAIMenuService {
    private TestAIKnowledgeService $knowledgeSvc;
    private TestAISettingsRepository $settingsRepo;

    public function __construct(TestAIKnowledgeService $knowledgeSvc, TestAISettingsRepository $settingsRepo) {
        $this->knowledgeSvc = $knowledgeSvc;
        $this->settingsRepo = $settingsRepo;
    }

    public function getBreakfastsText(int $limit = 20): string {
        $limit = max(1, min(50, $limit));
        $items = $this->getMenuItems();
        if (!$items) return '';
        $breakfasts = $this->itemsBySection($items, 'Кухня', 'Завтраки');
        if (!$breakfasts) return '';
        $lines = [];
        foreach (array_slice($breakfasts, 0, $limit) as $it) {
            $lines[] = (string)($it['name'] ?? '') . ' — ' . $this->fmtPrice((int)($it['price'] ?? 0), (string)($it['currency'] ?? '₫'));
        }
        $txt = trim(implode("\n", array_filter($lines, fn($x) => trim((string)$x) !== '')));
        return $txt !== '' ? ("Завтраки (до 15:00):\n" . $txt) : '';
    }

    public function getMostExpensiveKitchenText(): string {
        $items = $this->getMenuItems();
        if (!$items) return '';
        $max = $this->maxItem($items, 'Кухня');
        if (!$max) return '';
        $name = trim((string)($max['name'] ?? ''));
        $price = (int)($max['price'] ?? 0);
        $cur = (string)($max['currency'] ?? '₫');
        if ($name === '' || $price <= 0) return '';
        return 'Самое дорогое блюдо: ' . $name . ' — ' . $this->fmtPrice($price, $cur) . '.';
    }

    public function getKitchenDishCountText(): string {
        $items = $this->getMenuItems();
        if (!$items) return '';
        $count = $this->countKitchenDishes($items);
        return $count > 0 ? ('В меню кухни сейчас ' . $count . ' блюд (без доп. позиций и бара).') : '';
    }

    private function parseMenuText(string $text): array {
        $lines = preg_split("/\r?\n/u", $text);
        $items = [];
        $group = '';
        $section = '';
        $lastName = '';

        foreach (is_array($lines) ? $lines : [] as $raw) {
            $line = trim((string)$raw);
            if ($line === '') continue;
            if (mb_strlen($line) > 160) $line = mb_substr($line, 0, 160);

            if ($line === 'Кухня' || $line === 'Бар') { $group = $line; $section = ''; $lastName = ''; continue; }
            if (preg_match('/^Последнее обновление меню:/u', $line)) break;

            if (preg_match('/^(\d[\d\s\.]{0,20})\s*(₫|VND|vnd)$/u', $line, $m)) {
                $price = (int)preg_replace('/\D+/', '', (string)($m[1] ?? '0'));
                $cur = (string)($m[2] ?? '₫');
                if ($lastName !== '' && $price > 0) {
                    $items[] = ['group' => $group, 'section' => $section, 'name' => $lastName, 'price' => $price, 'currency' => $cur];
                }
                $lastName = '';
                continue;
            }

            if (preg_match('/\d$/u', $line) && !preg_match('/₫|VND|vnd/u', $line) && mb_strlen($line) <= 70) {
                $s = preg_replace('/\d+$/u', '', $line);
                $s = trim((string)$s);
                if ($s !== '' && $s !== 'Кухня' && $s !== 'Бар') $section = $s;
                $lastName = '';
                continue;
            }

            if (preg_match('/^(Блюдо кухни|Напиток\.|Горячий суп|Классический|мягкий|насыщенный|ферментированный|охлаждённое|свежевыжатый|освежающий)/u', $line)) continue;

            $lastName = $line;
        }

        return ['items' => $items];
    }

    private function itemsBySection(array $items, string $group, string $sectionPrefix): array {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (($it['group'] ?? '') !== $group) continue;
            $sec = (string)($it['section'] ?? '');
            if ($sec === '' || stripos($sec, $sectionPrefix) !== 0) continue;
            $out[] = $it;
        }
        return $out;
    }

    private function maxItem(array $items, string $group): array {
        $max = null;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (($it['group'] ?? '') !== $group) continue;
            $sec = (string)($it['section'] ?? '');
            if (stripos($sec, 'Дополнительно') === 0) continue;
            $p = (int)($it['price'] ?? 0);
            if ($p <= 0) continue;
            if ($max === null || $p > (int)$max['price']) $max = $it;
        }
        return is_array($max) ? $max : [];
    }

    private function countKitchenDishes(array $items): int {
        $c = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (($it['group'] ?? '') !== 'Кухня') continue;
            $sec = (string)($it['section'] ?? '');
            if (stripos($sec, 'Дополнительно') === 0) continue;
            if (stripos($sec, 'Детское меню') === 0) continue;
            $c++;
        }
        return $c;
    }

    private function fmtPrice(int $n, string $cur): string {
        $s = number_format(max(0, $n), 0, '.', ' ');
        if ($cur === 'VND' || $cur === 'vnd') $cur = '₫';
        return $s . ' ' . $cur;
    }

    private function getMenuItems(): array {
        $behRow = $this->settingsRepo->getKey('bot_behavior_json');
        $beh = json_decode((string)($behRow['v'] ?? ''), true);
        if (!is_array($beh)) $beh = [];
        $cfg = is_array($beh['menu_service'] ?? null) ? $beh['menu_service'] : [];
        if (empty($cfg['enable'])) return [];

        $url = trim((string)($cfg['menu_url'] ?? ''));
        if ($url === '') return [];
        $maxLen = (int)($cfg['max_len'] ?? 60000);
        $maxLen = max(5000, min(60000, $maxLen));
        $text = $this->knowledgeSvc->fetchLiveTextForUrl($url, $maxLen);
        if ($text === '') return [];
        $menu = $this->parseMenuText($text);
        return is_array($menu['items'] ?? null) ? $menu['items'] : [];
    }
}

class TestAIChatAgentService {
    private TestAIConfig $cfg;
    private TestAIGeminiClient $gemini;
    private TestAIHtmlSanitizer $sanitizer;
    private TestAISettingsRepository $settingsRepo;
    private TestAIKnowledgeService $knowledgeSvc;
    private TestAIMenuService $menuSvc;
    private TestAIDailySummariesRepository $dailyRepo;
    private TestAIDailySummaryService $dailySvc;
    private ?TestAILogger $log;

    public function __construct(
        TestAIConfig $cfg,
        TestAIGeminiClient $gemini,
        TestAIHtmlSanitizer $sanitizer,
        TestAISettingsRepository $settingsRepo,
        TestAIKnowledgeService $knowledgeSvc,
        TestAIMenuService $menuSvc,
        TestAIDailySummariesRepository $dailyRepo,
        TestAIDailySummaryService $dailySvc,
        ?TestAILogger $log = null
    ) {
        $this->cfg = $cfg;
        $this->gemini = $gemini;
        $this->sanitizer = $sanitizer;
        $this->settingsRepo = $settingsRepo;
        $this->knowledgeSvc = $knowledgeSvc;
        $this->menuSvc = $menuSvc;
        $this->dailyRepo = $dailyRepo;
        $this->dailySvc = $dailySvc;
        $this->log = $log;
    }

    public function answerChat(string $system, array $payload): array {
        $q = trim((string)($payload['question'] ?? ''));
        if ($q === '') return ['html' => '', 'trace' => ['error' => 'missing_question']];

        $beh = $this->loadBehavior();
        $agentCfg = is_array($beh['agent'] ?? null) ? $beh['agent'] : [];
        $chatCfg = is_array($beh['chat'] ?? null) ? $beh['chat'] : [];
        $append = trim((string)($chatCfg['system_append'] ?? ''));
        if ($append !== '') $system = rtrim($system) . "\n\n" . $append;
        if (isset($agentCfg['enable']) && empty($agentCfg['enable'])) {
            return $this->fallbackSingleCall($system, $payload, ['disabled' => 1]);
        }

        $tools = $this->getTools($beh);
        $trace = [];

        $plan = $this->plan($q, $payload, $tools, $agentCfg);
        $trace['plan'] = $plan;
        if (!is_array($plan)) return $this->fallbackSingleCall($system, $payload, ['plan_failed' => 1]);

        $direct = trim((string)($plan['direct_answer_html'] ?? ''));
        $calls = $plan['calls'] ?? [];
        if ($direct !== '' && (!is_array($calls) || !$calls)) {
            $direct = $this->sanitizer->sanitizeTelegramHtml($direct);
            return ['html' => $direct, 'trace' => $trace];
        }

        $toolResults = $this->executeCalls(is_array($calls) ? $calls : [], $tools, $agentCfg);
        $trace['tool_results'] = $toolResults;

        $final = $this->finalize($system, $payload, $toolResults, $agentCfg);
        $trace['final'] = [
            'http_code' => (int)($final['_http_code'] ?? 0),
            'has_error' => !empty($final['error']) ? 1 : 0,
        ];

        $html = $this->gemini->text($final);
        $html = $this->sanitizer->sanitizeTelegramHtml($html);
        if ($html === '') $html = $this->fallbackTextFromError($final);
        return ['html' => $html, 'trace' => $trace];
    }

    private function fallbackSingleCall(string $system, array $payload, array $trace): array {
        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => 0.35, 'maxOutputTokens' => 1200, 'tag' => 'chat_reply_fallback']
        );
        $this->handleRateLimit($resp);
        $html = $this->gemini->text($resp);
        $html = $this->sanitizer->sanitizeTelegramHtml($html);
        if ($html === '') $html = $this->fallbackTextFromError($resp);
        return ['html' => $html, 'trace' => $trace];
    }

    private function plan(string $question, array $payload, array $tools, array $agentCfg): ?array {
        $maxCalls = (int)($agentCfg['max_calls'] ?? 3);
        $maxCalls = max(0, min(6, $maxCalls));
        $planTemp = (float)($agentCfg['plan_temp'] ?? 0.1);

        $toolLines = [];
        foreach ($tools as $t) {
            if (!is_array($t)) continue;
            if (empty($t['enabled'])) continue;
            $name = (string)($t['name'] ?? '');
            $desc = (string)($t['desc'] ?? '');
            if ($name === '') continue;
            $toolLines[] = '- ' . $name . ': ' . $desc;
        }
        $toolText = $toolLines ? implode("\n", $toolLines) : '- (no tools)';

        $system = "You are a tool router. Decide which tools to call to answer the user's question.\n\nAvailable tools:\n{$toolText}\n\nReturn strict JSON only:\n{\n  \"direct_answer_html\": \"\",\n  \"calls\": [\n    {\"tool\":\"...\",\"args\":{}}\n  ]\n}\n\nRules:\n- Choose 0..{$maxCalls} calls.\n- If tools are needed, do not answer directly; leave direct_answer_html empty.\n- If no tools are needed, put the final Telegram-HTML answer into direct_answer_html and leave calls empty.\n- Never invent data. Prefer calling tools when asked about menu, prices, announcements, bookings, contacts, or facts.\n";

        $p = [
            'mode' => 'chat',
            'question' => $question,
            'lang' => (string)($payload['lang'] ?? ''),
            'context' => $payload['context'] ?? [],
            'now_date' => date('Y-m-d'),
        ];

        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [['text' => json_encode($p, JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => $planTemp, 'maxOutputTokens' => 700, 'responseMimeType' => 'application/json', 'tag' => 'agent_plan']
        );
        $this->handleRateLimit($resp);
        $j = $this->gemini->json($resp);
        if (!is_array($j)) return null;

        $calls = $j['calls'] ?? [];
        if (!is_array($calls)) $calls = [];
        $outCalls = [];
        foreach ($calls as $c) {
            if (!is_array($c)) continue;
            $tool = trim((string)($c['tool'] ?? ''));
            if ($tool === '') continue;
            if (!$this->isToolEnabled($tools, $tool)) continue;
            $args = is_array($c['args'] ?? null) ? $c['args'] : [];
            $outCalls[] = ['tool' => $tool, 'args' => $args];
            if (count($outCalls) >= $maxCalls) break;
        }

        return [
            'direct_answer_html' => (string)($j['direct_answer_html'] ?? ''),
            'calls' => $outCalls,
        ];
    }

    private function executeCalls(array $calls, array $tools, array $agentCfg): array {
        $allowDailyGenerate = !empty($agentCfg['allow_daily_generate']);
        $out = [];
        foreach ($calls as $c) {
            if (!is_array($c)) continue;
            $tool = trim((string)($c['tool'] ?? ''));
            $args = is_array($c['args'] ?? null) ? $c['args'] : [];
            if ($tool === '' || !$this->isToolEnabled($tools, $tool)) continue;

            if ($tool === 'kb_search') {
                $query = trim((string)($args['query'] ?? ''));
                $limit = (int)($args['limit'] ?? 5);
                $limit = max(1, min(8, $limit));
                $docs = $query !== '' ? $this->knowledgeSvc->selectForQuestion($query, $limit) : [];
                $out[] = ['tool' => $tool, 'ok' => true, 'data' => ['query' => $query, 'docs' => $docs]];
                continue;
            }

            if ($tool === 'kb_fetch_url') {
                $url = trim((string)($args['url'] ?? ''));
                $maxLen = (int)($args['max_len'] ?? 60000);
                $maxLen = max(500, min(60000, $maxLen));
                $txt = $url !== '' ? $this->knowledgeSvc->fetchLiveTextForUrl($url, $maxLen) : '';
                $out[] = ['tool' => $tool, 'ok' => $txt !== '', 'data' => ['url' => $url, 'text' => $txt]];
                continue;
            }

            if ($tool === 'daily_get') {
                $day = trim((string)($args['day'] ?? date('Y-m-d')));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');
                $row = $this->dailyRepo->getByDay($day);
                $ej = is_array($row) ? json_decode((string)($row['events_json'] ?? '[]'), true) : null;
                if (!is_array($ej)) $ej = [];
                $out[] = [
                    'tool' => $tool,
                    'ok' => is_array($row) ? true : false,
                    'data' => [
                        'day' => $day,
                        'exists' => is_array($row) ? 1 : 0,
                        'updated_at' => is_array($row) ? (string)($row['updated_at'] ?? '') : '',
                        'summary_text' => is_array($row) ? (string)($row['summary_text'] ?? '') : '',
                        'events' => $ej,
                    ],
                ];
                continue;
            }

            if ($tool === 'daily_generate') {
                if (!$allowDailyGenerate) {
                    $out[] = ['tool' => $tool, 'ok' => false, 'error' => 'disabled'];
                    continue;
                }
                $day = trim((string)($args['day'] ?? date('Y-m-d')));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');
                $ok = $this->dailySvc->runDay($day);
                $row = $this->dailyRepo->getByDay($day);
                $out[] = ['tool' => $tool, 'ok' => $ok ? true : false, 'data' => ['day' => $day, 'exists' => is_array($row) ? 1 : 0]];
                continue;
            }

            if ($tool === 'menu_breakfasts') {
                $limit = (int)($args['limit'] ?? 20);
                $txt = $this->menuSvc->getBreakfastsText($limit);
                $out[] = ['tool' => $tool, 'ok' => $txt !== '', 'data' => ['text' => $txt]];
                continue;
            }

            if ($tool === 'menu_most_expensive') {
                $txt = $this->menuSvc->getMostExpensiveKitchenText();
                $out[] = ['tool' => $tool, 'ok' => $txt !== '', 'data' => ['text' => $txt]];
                continue;
            }

            if ($tool === 'menu_count_kitchen') {
                $txt = $this->menuSvc->getKitchenDishCountText();
                $out[] = ['tool' => $tool, 'ok' => $txt !== '', 'data' => ['text' => $txt]];
                continue;
            }
        }
        return $out;
    }

    private function finalize(string $system, array $payload, array $toolResults, array $agentCfg): array {
        $finalTemp = (float)($agentCfg['final_temp'] ?? 0.35);
        $finalMax = (int)($agentCfg['final_max_tokens'] ?? 1200);
        $finalMax = max(200, min(2500, $finalMax));

        $sys = rtrim($system) . "\n\nUse payload.tool_results. If data is missing, say you don't have it. Do not invent.\n";
        $p = $payload;
        $p['tool_results'] = $toolResults;

        $resp = $this->gemini->generate(
            $this->cfg->geminiModel,
            [['text' => json_encode($p, JSON_UNESCAPED_UNICODE)]],
            ['system' => $sys, 'temperature' => $finalTemp, 'maxOutputTokens' => $finalMax, 'tag' => 'agent_final']
        );
        $this->handleRateLimit($resp);
        return $resp;
    }

    private function getTools(array $beh): array {
        $tools = [];
        if (is_array($beh['tools'] ?? null)) {
            foreach ($beh['tools'] as $t) {
                if (!is_array($t)) continue;
                $name = trim((string)($t['name'] ?? ''));
                if ($name === '') continue;
                $tools[] = [
                    'name' => $name,
                    'enabled' => !empty($t['enabled']) ? 1 : 0,
                    'desc' => trim((string)($t['desc'] ?? '')),
                ];
            }
        }
        if ($tools) return $tools;
        return [
            ['name' => 'kb_search', 'enabled' => 1, 'desc' => 'Search knowledge base by query. args: {query,limit}'],
            ['name' => 'kb_fetch_url', 'enabled' => 1, 'desc' => 'Fetch veranda.my URL and extract text. args: {url,max_len}'],
            ['name' => 'daily_get', 'enabled' => 1, 'desc' => 'Get daily summary and events for day from DB. args: {day}'],
            ['name' => 'daily_generate', 'enabled' => 0, 'desc' => 'Generate daily summary for day (costly). args: {day}'],
            ['name' => 'menu_breakfasts', 'enabled' => 1, 'desc' => 'List breakfasts from menu. args: {limit}'],
            ['name' => 'menu_most_expensive', 'enabled' => 1, 'desc' => 'Most expensive kitchen dish. args: {}'],
            ['name' => 'menu_count_kitchen', 'enabled' => 1, 'desc' => 'Count kitchen dishes. args: {}'],
        ];
    }

    private function isToolEnabled(array $tools, string $name): bool {
        foreach ($tools as $t) {
            if (!is_array($t)) continue;
            if ((string)($t['name'] ?? '') !== $name) continue;
            return !empty($t['enabled']);
        }
        return false;
    }

    private function loadBehavior(): array {
        $row = $this->settingsRepo->getKey('bot_behavior_json');
        $decoded = json_decode((string)($row['v'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function handleRateLimit(array $resp): void {
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
    }

    private function fallbackTextFromError(array $resp): string {
        $err = '';
        if (is_array($resp['error'] ?? null)) $err = trim((string)($resp['error']['message'] ?? ''));
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
}
