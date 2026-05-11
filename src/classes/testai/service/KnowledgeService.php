<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\GeminiClient;
use App\Classes\TestAI\Infra\UrlFetcher;
use App\Classes\TestAI\Repository\KnowledgeRepository;

class KnowledgeService {
    private const LIVE_FETCH_MAX  = 2;
    private const CONTENT_MAX_LEN = 2000;

    public function __construct(
        private KnowledgeRepository $repo,
        private UrlFetcher          $fetcher,
        private ?GeminiClient       $gemini = null,
        private string              $model  = ''
    ) {}

    /**
     * Find relevant KB docs for a question.
     * Tries FULLTEXT first, falls back to keyword LIKE.
     * Respects access level.
     */
    public function search(string $question, bool $authorized, int $limit = 6): array {
        $question = trim($question);
        if ($question === '') return [];

        // Prefer FULLTEXT search
        $rows = $this->repo->searchFulltext($question, $authorized, $limit);

        // Fallback to keyword LIKE if FULLTEXT returned nothing
        if (!$rows) {
            $keywords = $this->extractKeywords($question);
            if ($keywords) {
                $rows = $this->repo->searchByKeywords($keywords, $authorized, $limit);
            }
        }

        return $this->hydrateRows($rows);
    }

    /**
     * Auto-classify a KB document.
     * Returns ['category' => string, 'tags' => string] or defaults.
     */
    public function categorizeDoc(string $title, string $content): array {
        if ($this->gemini === null || $this->model === '') {
            return ['category' => 'other', 'tags' => ''];
        }

        $text   = mb_substr(trim($title . "\n\n" . $content), 0, 1200);
        $prompt = <<<PROMPT
Classify this knowledge base article. Return ONLY valid JSON, no explanation.
JSON keys: "category" (string) and "tags" (array of strings).

Categories: contacts, hours, menu, events, policies, location, team, other

Rules:
- tags: 3-5 short Russian keywords most relevant to this document
- category: pick the single best match

Document:
{$text}
PROMPT;

        $resp = $this->gemini->generate(
            $this->model,
            [['text' => $prompt]],
            ['temperature' => 0.1, 'maxOutputTokens' => 150, 'responseMimeType' => 'application/json', 'tag' => 'kb_categorize']
        );

        $j = $this->gemini->json($resp);
        if (!is_array($j)) return ['category' => 'other', 'tags' => ''];

        $category = in_array($j['category'] ?? '', ['contacts','hours','menu','events','policies','location','team','other'], true)
            ? (string)$j['category']
            : 'other';

        $tagsArr = is_array($j['tags'] ?? null) ? $j['tags'] : [];
        $tags    = implode(', ', array_map('strval', array_slice($tagsArr, 0, 6)));

        return ['category' => $category, 'tags' => $tags];
    }

    /** Fetch a specific URL and return its text (for admin import). */
    public function fetchUrl(string $url, int $maxLen = 20000): string {
        return $this->fetcher->fetch($url, $maxLen);
    }

    private function hydrateRows(array $rows): array {
        $out       = [];
        $liveCount = 0;

        foreach ($rows as $r) {
            $content   = trim((string)($r['content'] ?? ''));
            $sourceUrl = trim((string)($r['source_url'] ?? ''));

            if ($content === '' && $sourceUrl !== '' && $liveCount < self::LIVE_FETCH_MAX) {
                $fetched = $this->fetcher->fetch($sourceUrl, 10000);
                if ($fetched !== '') {
                    $content = $fetched;
                    $liveCount++;
                }
            }

            if ($content === '') continue;
            if (mb_strlen($content) > self::CONTENT_MAX_LEN) {
                $content = mb_substr($content, 0, self::CONTENT_MAX_LEN) . '…';
            }

            $out[] = [
                'title'    => trim((string)($r['title'] ?? '')),
                'content'  => $content,
                'category' => trim((string)($r['category'] ?? 'other')),
            ];
        }
        return $out;
    }

    private function extractKeywords(string $question): array {
        $q    = mb_strtolower(trim($question));
        $q    = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q) ?? $q;
        $parts = preg_split('/\s+/u', trim($q)) ?: [];

        $stop = ['какие','какой','какая','какое','есть','у','вас','в','на','и','или','что','это',
                  'нет','там','по','сколько','пожалуйста','спасибо','the','is','are','what','how'];

        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '' || mb_strlen($p) < 3) continue;
            if (in_array($p, $stop, true)) continue;
            $out[$p] = true;
            if (count($out) >= 10) break;
        }

        if (preg_match('/\b(меню|блюд|позици|ассортимент)\b/u', $q))             $out['меню'] = true;
        if (preg_match('/\b(завтрак|breakfast)\b/u', $q))                         { $out['завтрак'] = true; $out['меню'] = true; }
        if (preg_match('/\b(бар|пиво|вино|коктейл)\b/u', $q))                    { $out['бар'] = true; $out['меню'] = true; }
        if (preg_match('/\b(цен|стоит|стоят|сколько)\b/u', $q))                  $out['цена'] = true;
        if (preg_match('/\b(анонс|афиш|событи|мероприят|концерт|live)\b/u', $q)) { $out['анонс'] = true; $out['афиша'] = true; }

        return array_keys($out);
    }
}
