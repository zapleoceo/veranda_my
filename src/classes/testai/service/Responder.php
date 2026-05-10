<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\GeminiClient;
use App\Classes\TestAI\Infra\HtmlSanitizer;
use App\Classes\TestAI\Repository\SettingsRepository;

/**
 * Answers a chat question using exactly ONE Gemini call.
 *
 * Flow:
 *   1. PHP detects topic (menu or KB) — no AI call.
 *   2. PHP loads data: menu from DB, KB docs filtered by access level.
 *   3. Single Gemini call: system + data + question → answer.
 *
 * Privacy is enforced at two levels:
 *   - SQL: 'members' docs only returned for authorized chats.
 *   - System prompt: forbidden topics injected as hard NEVER rules.
 *   - MenuRepository: cost_raw is never selected, only price_raw.
 */
class Responder {
    private const MENU_KEYWORDS = [
        'меню','блюд','завтрак','бизнес-ланч','стоит','цена','цены','ценник',
        'заказ','кухня','бар','напит','десерт','салат','суп','горяч','закуск',
        'пицц','паст','стейк','рыб','морепрод','шашлык','карп','курин','говяд',
        'menu','price','dish','food','drink','breakfast','lunch','dinner','beer','wine',
    ];

    public function __construct(
        private string              $model,
        private GeminiClient        $gemini,
        private HtmlSanitizer       $sanitizer,
        private SettingsRepository  $settings,
        private KnowledgeService    $knowledgeSvc,
        private MenuService         $menuSvc
    ) {}

    /**
     * @param string $question     The user's question
     * @param array  $context      Recent chat messages [{from, text, at}]
     * @param bool   $isAuthorized True if chat is in allowed list
     * @return string              Sanitized Telegram HTML answer
     */
    public function respond(string $question, array $context, bool $isAuthorized): string {
        $question = trim($question);
        if ($question === '') return '';

        $lang = $this->detectLang($question, $context);

        // 1. PHP topic detection — no AI
        $needsMenu = $this->isMenuQuestion($question);

        // 2. Load data in parallel (both are cheap SQL / filtered text)
        $menuText = $needsMenu ? $this->menuSvc->getMenuText($lang === 'en' ? 'en' : 'ru') : '';
        $kbDocs   = $this->knowledgeSvc->search($question, $isAuthorized);

        // 3. Build system prompt
        $system = $this->buildSystem($lang);

        // 4. Build payload
        $payload = [
            'question' => $question,
            'lang'     => $lang,
            'context'  => $this->trimContext($context),
        ];
        if ($menuText !== '') $payload['menu']      = $menuText;
        if ($kbDocs)          $payload['knowledge'] = $kbDocs;

        // 5. ONE Gemini call
        $resp = $this->gemini->generate(
            $this->model,
            [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
            ['system' => $system, 'temperature' => 0.3, 'maxOutputTokens' => 1200, 'tag' => 'chat_reply']
        );

        $html = $this->gemini->text($resp);
        if ($html === '') $html = $this->errorText($resp, $lang);

        return $this->sanitizer->sanitizeTelegramHtml($html);
    }

    private function buildSystem(string $lang): string {
        $identity  = trim($this->settings->get('bot_identity'));
        $forbidden = trim($this->settings->get('bot_forbidden'));

        $parts = [];

        if ($identity !== '') {
            $parts[] = $identity;
        }

        if ($forbidden !== '') {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $forbidden))));
            if ($lines) {
                $list    = implode('; ', $lines);
                $parts[] = "СТРОГО ЗАПРЕЩЕНО упоминать, раскрывать или обсуждать: {$list}."
                         . " Если об этом спрашивают — отвечай что такой информации у тебя нет.";
            }
        }

        $parts[] = "Отвечай в Telegram HTML. Разрешённые теги: <b>, <i>, <code>, <pre>, <a href=\"...\">."
                 . " Не используй <br> — вместо этого перевод строки."
                 . " Не используй markdown (**, __, ```, #).";

        $parts[] = "Язык ответа: " . strtoupper($lang) . "."
                 . " Если пользователь написал на другом языке — отвечай на его языке.";

        $parts[] = "Если в запросе есть поле 'menu' — используй его для вопросов о блюдах и ценах."
                 . " Если есть поле 'knowledge' — используй его как источник фактов."
                 . " Не придумывай цены, факты и данные которых нет в переданных данных.";

        return implode("\n\n", $parts);
    }

    private function isMenuQuestion(string $question): bool {
        $q = mb_strtolower($question);
        foreach (self::MENU_KEYWORDS as $kw) {
            if (mb_strpos($q, $kw) !== false) return true;
        }
        return false;
    }

    private function detectLang(string $question, array $context): string {
        if (preg_match('/\p{Cyrillic}/u', $question)) return 'ru';
        if (preg_match('/[A-Za-z]{3}/u', $question))  return 'en';
        // fallback: scan recent context
        foreach (array_reverse($context) as $msg) {
            $t = (string)($msg['text'] ?? '');
            if (preg_match('/\p{Cyrillic}/u', $t)) return 'ru';
            if (preg_match('/[A-Za-z]{3}/u', $t))  return 'en';
        }
        return 'ru';
    }

    /** Keep context concise — last 15 messages, max 800 chars each. */
    private function trimContext(array $context): array {
        $out = [];
        foreach (array_slice($context, -15) as $m) {
            if (!is_array($m)) continue;
            $text = trim((string)($m['text'] ?? ''));
            if ($text === '') continue;
            if (mb_strlen($text) > 800) $text = mb_substr($text, 0, 800) . '…';
            $out[] = ['from' => (string)($m['from'] ?? ''), 'text' => $text];
        }
        return $out;
    }

    private function errorText(array $resp, string $lang): string {
        $err = '';
        if (is_array($resp['error'] ?? null)) $err = trim((string)($resp['error']['message'] ?? ''));
        if ($err !== '' && preg_match('/quota|rate.?limit/i', $err)) {
            $retry = '';
            if (preg_match('/retry in\s*([0-9.]+)s/i', $err, $m)) {
                $retry = ($lang === 'ru' ? ' Попробуйте через ' : ' Try again in ') . (int)ceil((float)$m[1]) . ' сек.';
            }
            return $lang === 'ru'
                ? 'Лимит запросов к AI исчерпан.' . $retry
                : 'AI rate limit reached.' . $retry;
        }
        return $lang === 'ru' ? 'Не получилось сформировать ответ.' : 'Could not generate a response.';
    }
}
