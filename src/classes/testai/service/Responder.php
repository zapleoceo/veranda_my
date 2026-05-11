<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\GeminiClient;
use App\Classes\TestAI\Infra\HtmlSanitizer;
use App\Classes\TestAI\Repository\SettingsRepository;

/**
 * Answers a chat question using Gemini function calling (tool use).
 *
 * Flow:
 *   1. Detect language (no AI).
 *   2. Check response cache for short, context-free questions.
 *   3. Step 1 Gemini call: question + context + tool declarations → Gemini decides which tools to use.
 *   4. If Gemini returns functionCall(s): PHP dispatches each → collects results.
 *   5. Step 2 Gemini call: previous turn + tool results → final answer.
 *   6. Sanitize HTML, store in cache, return.
 *
 * Privacy enforced by:
 *   - ToolDispatcher: authorized flag passed to every tool call.
 *   - System prompt: forbidden topics as hard NEVER rules.
 */
class Responder {
    private const CACHE_TTL = 600; // 10 min

    public function __construct(
        private string             $model,
        private GeminiClient       $gemini,
        private HtmlSanitizer      $sanitizer,
        private SettingsRepository $settings,
        private ToolDispatcher     $toolDispatcher
    ) {}

    /**
     * @param string $question     User's question
     * @param array  $context      Recent chat messages [{from, text, at}]
     * @param bool   $isAuthorized True if chat is authorized (sees 'members' KB docs)
     * @return string              Sanitized Telegram HTML answer
     */
    public function respond(string $question, array $context, bool $isAuthorized): string {
        $question = trim($question);
        if ($question === '') return '';

        $lang = $this->detectLang($question, $context);

        // Cache only for simple one-shot questions (no/minimal context)
        $cacheKey = null;
        if (count($context) <= 1) {
            $cacheKey = '_rc:' . sha1(mb_strtolower($question) . ':' . ($isAuthorized ? '1' : '0'));
            $cached   = $this->getCache($cacheKey);
            if ($cached !== null) return $cached;
        }

        $system   = $this->buildSystem($lang);
        $toolDecls = $this->toolDispatcher->getDeclarations();
        $contents  = $this->buildContents($question, $context, $lang);

        // Step 1: Gemini decides which tools to call (or answers directly)
        $resp1     = $this->gemini->generateWithTools(
            $this->model, $contents, $toolDecls,
            ['system' => $system, 'temperature' => 0.3, 'maxOutputTokens' => 8192, 'tag' => 'chat_step1']
        );
        $toolCalls = $this->gemini->extractToolCalls($resp1);

        if ($toolCalls) {
            // Dispatch each tool call
            $toolResults = [];
            foreach ($toolCalls as $tc) {
                $result        = $this->toolDispatcher->dispatch($tc['name'], $tc['args'], $isAuthorized, $lang);
                $toolResults[] = ['name' => $tc['name'], 'result' => $result];
            }

            // Step 2: Second call with tool results injected
            $contents2 = $this->appendToolResults($contents, $resp1, $toolResults);
            $resp2     = $this->gemini->generateWithTools(
                $this->model, $contents2, $toolDecls,
                ['system' => $system, 'temperature' => 0.3, 'maxOutputTokens' => 8192, 'tag' => 'chat_step2']
            );

            $html = $this->gemini->text($resp2);
            if ($html === '') {
                $this->storeRateLimitBlock($resp2);
                $html = $this->errorText($resp2, $lang, $this->waitSecFromSettings());
            }
        } else {
            // Direct text answer (no tools needed)
            $html = $this->gemini->text($resp1);
            if ($html === '') {
                $this->storeRateLimitBlock($resp1);
                $html = $this->errorText($resp1, $lang, $this->waitSecFromSettings());
            }
        }

        $html = $this->sanitizer->sanitizeTelegramHtml($html);

        if ($cacheKey !== null && $html !== ''
            && !str_contains($html, 'Лимит') && !str_contains($html, 'Не получилось')) {
            $this->setCache($cacheKey, $html);
        }

        return $html;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function buildContents(string $question, array $context, string $lang): array {
        $payload = [
            'question' => $question,
            'lang'     => $lang,
            'context'  => $this->trimContext($context),
        ];
        return [
            ['role' => 'user', 'parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
        ];
    }

    private function appendToolResults(array $contents, array $modelResp, array $toolResults): array {
        // Append model's turn (with functionCall parts)
        $modelParts = $modelResp['candidates'][0]['content']['parts'] ?? [];
        if ($modelParts) {
            $contents[] = ['role' => 'model', 'parts' => $modelParts];
        }

        // Append tool results as functionResponse parts
        $responseParts = [];
        foreach ($toolResults as $tr) {
            $result = is_array($tr['result']) ? $tr['result'] : ['result' => (string)$tr['result']];
            $responseParts[] = [
                'functionResponse' => [
                    'name'     => $tr['name'],
                    'response' => $result,
                ],
            ];
        }
        if ($responseParts) {
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        return $contents;
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

        $parts[] = "Используй доступные инструменты для получения актуальных данных. Не придумывай факты, цены и составы блюд.";

        $parts[] = "Отвечай в Telegram HTML. Разрешённые теги: <b>, <i>, <code>, <pre>, <a href=\"...\">."
                 . " Не используй <br> — вместо этого перевод строки."
                 . " Не используй markdown (**, __, ```, #).";

        $parts[] = "Язык ответа: " . strtoupper($lang) . "."
                 . " Если пользователь написал на другом языке — отвечай на его языке.";

        return implode("\n\n", $parts);
    }

    private function detectLang(string $question, array $context): string {
        if (preg_match('/\p{Cyrillic}/u', $question)) return 'ru';
        if (preg_match('/[A-Za-z]{3}/u', $question))  return 'en';
        foreach (array_reverse($context) as $msg) {
            $t = (string)($msg['text'] ?? '');
            if (preg_match('/\p{Cyrillic}/u', $t)) return 'ru';
            if (preg_match('/[A-Za-z]{3}/u', $t))  return 'en';
        }
        return 'ru';
    }

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

    private function errorText(array $resp, string $lang, int $waitSec = 0): string {
        $err = '';
        if (is_array($resp['error'] ?? null)) $err = trim((string)($resp['error']['message'] ?? ''));
        if ($this->isDailyLimitError($err)) {
            return $lang === 'ru'
                ? 'Дневной лимит запросов к AI исчерпан. Попробуйте завтра.'
                : 'Daily AI request limit reached. Try again tomorrow.';
        }
        if ($err !== '' && preg_match('/quota|rate.?limit/i', $err)) {
            $retry = '';
            if ($waitSec > 0) {
                $retry = ($lang === 'ru' ? ' Попробуйте через ' : ' Try again in ') . $this->fmtWait($waitSec, $lang) . '.';
            } elseif (preg_match('/retry in\s*([0-9.]+)s/i', $err, $m)) {
                $retry = ($lang === 'ru' ? ' Попробуйте через ' : ' Try again in ') . $this->fmtWait((int)ceil((float)$m[1]), $lang) . '.';
            }
            return $lang === 'ru'
                ? 'Лимит запросов к AI исчерпан.' . $retry
                : 'AI rate limit reached.' . $retry;
        }
        return $lang === 'ru' ? 'Не получилось сформировать ответ.' : 'Could not generate a response.';
    }

    private function getCache(string $key): ?string {
        $raw = $this->settings->get($key);
        if ($raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) return null;
        $html = (string)($data['html'] ?? '');
        return $html !== '' ? $html : null;
    }

    private function setCache(string $key, string $html): void {
        $this->settings->set($key, json_encode(['html' => $html, 'exp' => time() + self::CACHE_TTL], JSON_UNESCAPED_UNICODE) ?: '');
        if (random_int(1, 20) === 1) {
            try { $this->settings->deleteExpiredCache('_rc:'); } catch (\Throwable) {}
        }
    }

    private function storeRateLimitBlock(array $resp): void {
        if (!is_array($resp['error'] ?? null)) return;
        $err = trim((string)($resp['error']['message'] ?? ''));
        if ($err === '' || !preg_match('/quota|rate.?limit/i', $err)) return;
        $retry = 60;
        if (preg_match('/retry in\s*([0-9.]+)s/i', $err, $m)) $retry = (int)ceil((float)($m[1] ?? 0));
        $until = $this->isDailyLimitError($err) ? time() + 6 * 3600 : time() + max(90, $retry + 10);
        $ts = gmdate('c', $until);
        $this->settings->set('gemini_block_until', $ts);
        $this->settings->set('gemini_next_allowed_until', $ts);
    }

    private function waitSecFromSettings(): int {
        $now  = time();
        $b    = strtotime((string)$this->settings->get('gemini_block_until'));
        $n    = strtotime((string)$this->settings->get('gemini_next_allowed_until'));
        $bRem = (is_int($b) && $b > $now) ? ($b - $now) : 0;
        $nRem = (is_int($n) && $n > $now) ? ($n - $now) : 0;
        return max($bRem, $nRem);
    }

    private function isDailyLimitError(string $err): bool {
        if ($err === '') return false;
        if (stripos($err, 'free_tier_requests') !== false && preg_match('/\blimit:\s*20\b/i', $err)) return true;
        if (preg_match('/requests\s+per\s+day|\\bRPD\\b/i', $err)) return true;
        return false;
    }

    private function fmtWait(int $sec, string $lang): string {
        $sec = max(0, $sec);
        if ($sec < 60) return (string)$sec . ($lang === 'ru' ? ' сек' : ' sec');
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($lang === 'ru') {
            $out = [];
            if ($h > 0) $out[] = $h . ' ч';
            if ($m > 0) $out[] = $m . ' мин';
            if ($h === 0 && $m === 0) $out[] = ($sec % 60) . ' сек';
            return implode(' ', $out);
        }
        $out = [];
        if ($h > 0) $out[] = $h . 'h';
        if ($m > 0) $out[] = $m . 'm';
        if ($h === 0 && $m === 0) $out[] = ($sec % 60) . 's';
        return implode(' ', $out);
    }
}
