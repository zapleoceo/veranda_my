<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\UrlFetcher;
use App\Classes\TestAI\Repository\KnowledgeRepository;

class KnowledgeService {
    private const LIVE_FETCH_MAX  = 2;
    private const CONTENT_MAX_LEN = 2000;

    public function __construct(
        private KnowledgeRepository $repo,
        private UrlFetcher          $fetcher
    ) {}

    /**
     * Find relevant KB docs for a question.
     * Respects access level (authorized = can see 'members' docs too).
     */
    public function search(string $question, bool $authorized, int $limit = 6): array {
        $keywords = $this->extractKeywords($question);
        if (!$keywords) return [];

        $rows       = $this->repo->searchByKeywords($keywords, $authorized, $limit);
        $out        = [];
        $liveCount  = 0;

        foreach ($rows as $r) {
            $content   = trim((string)($r['content'] ?? ''));
            $sourceUrl = trim((string)($r['source_url'] ?? ''));

            // Live-fetch if content empty and URL present
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
                'title'   => trim((string)($r['title'] ?? '')),
                'content' => $content,
            ];
        }
        return $out;
    }

    /** Fetch a specific URL and return its text (for admin import). */
    public function fetchUrl(string $url, int $maxLen = 20000): string {
        return $this->fetcher->fetch($url, $maxLen);
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

        // Domain-specific keyword boosting
        if (preg_match('/\b(меню|блюд|позици|ассортимент)\b/u', $q))        $out['меню'] = true;
        if (preg_match('/\b(завтрак|breakfast)\b/u', $q))                    { $out['завтрак'] = true; $out['меню'] = true; }
        if (preg_match('/\b(бар|пиво|вино|коктейл)\b/u', $q))               { $out['бар'] = true; $out['меню'] = true; }
        if (preg_match('/\b(цен|стоит|стоят|сколько)\b/u', $q))             $out['цена'] = true;
        if (preg_match('/\b(анонс|афиш|событи|мероприят|концерт|live)\b/u', $q)) { $out['анонс'] = true; $out['афиша'] = true; }

        return array_keys($out);
    }
}
