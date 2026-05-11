<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Infra\PosterClient;
use App\Classes\TestAI\Repository\DailyRepository;
use App\Classes\TestAI\Repository\EventRepository;
use App\Classes\TestAI\Repository\MessageRepository;

/**
 * Routes Gemini function calls to PHP services.
 * Each public method matches a declared tool name.
 */
class ToolDispatcher {
    public function __construct(
        private MenuService       $menuSvc,
        private KnowledgeService  $knowledgeSvc,
        private ?PosterClient     $poster,
        private EventRepository   $eventRepo,
        private DailyRepository   $dailyRepo,
        private MessageRepository $msgRepo
    ) {}

    /**
     * Dispatch a function call from Gemini.
     * @return array  Result data sent back as functionResponse.
     */
    public function dispatch(string $name, array $args, bool $authorized, string $lang = 'ru'): array {
        return match ($name) {
            'search_menu'          => $this->searchMenu($args, $lang),
            'get_dish_composition' => $this->getDishComposition($args),
            'get_availability'     => $this->getAvailability(),
            'search_events'        => $this->searchEvents($args),
            'search_knowledge'     => $this->searchKnowledge($args, $authorized),
            'get_weekly_summary'   => $this->getWeeklySummary($args),
            default                => ['error' => "Unknown tool: {$name}"],
        };
    }

    /** Gemini functionDeclaration objects for all available tools. */
    public function getDeclarations(): array {
        $decls = [
            [
                'name'        => 'search_menu',
                'description' => 'Search the restaurant menu for dishes, drinks, prices. Use for any food or drink related question.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query (Russian or English)'],
                        'lang'  => ['type' => 'string', 'description' => 'Language: ru or en', 'enum' => ['ru', 'en']],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'get_dish_composition',
                'description' => 'Get ingredients and composition of a specific dish from the POS system.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dish_name' => ['type' => 'string', 'description' => 'Dish name in Russian'],
                    ],
                    'required' => ['dish_name'],
                ],
            ],
            [
                'name'        => 'get_availability',
                'description' => 'Check which menu items are currently out of stock or unavailable.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'        => 'search_events',
                'description' => 'Search for upcoming or recent events, concerts, shows, film screenings, parties, DJ nights, live music, and announcements at the restaurant. Use whenever the user asks about events, schedule, what\'s on, what\'s happening, films or movies shown at the venue.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query'     => ['type' => 'string', 'description' => 'Event search query (e.g. film, concert, DJ, party)'],
                        'days_back' => ['type' => 'integer', 'description' => 'Days back to search (default 14)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'search_knowledge',
                'description' => 'Search the knowledge base for restaurant info: hours, location, contacts, policies, WiFi, parking, etc.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'get_weekly_summary',
                'description' => 'Get a daily summary of restaurant activity. Use when the user asks for a summary, digest, or recap for a specific day or period ("за вчера", "за понедельник", "за эту неделю").',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'date'      => ['type' => 'string', 'description' => 'Specific date YYYY-MM-DD. Use when user asks for a specific day (yesterday, Monday, etc.).'],
                        'days_back' => ['type' => 'integer', 'description' => 'Days to look back (1-7, default 7). Used when no specific date given.'],
                    ],
                ],
            ],
        ];

        // Remove composition/availability tools if Poster not configured
        if (!$this->poster?->isConfigured()) {
            $decls = array_values(array_filter($decls, fn($d) => !in_array($d['name'], ['get_dish_composition', 'get_availability'], true)));
        }

        return $decls;
    }

    // ─── Tool handlers ────────────────────────────────────────────────────────

    private function searchMenu(array $args, string $lang): array {
        $l       = (string)($args['lang'] ?? $lang);
        $menu    = $this->menuSvc->getMenuText($l === 'en' ? 'en' : 'ru');
        return ['menu' => $menu ?: 'Меню недоступно.'];
    }

    private function getDishComposition(array $args): array {
        if (!$this->poster?->isConfigured()) return ['error' => 'POS system not configured.'];
        $name   = trim((string)($args['dish_name'] ?? ''));
        if ($name === '') return ['error' => 'dish_name is required.'];
        return ['composition' => $this->poster->getDishComposition($name)];
    }

    private function getAvailability(): array {
        if (!$this->poster?->isConfigured()) return ['availability' => 'Система POS не подключена.'];
        return ['availability' => $this->poster->getAvailabilityText()];
    }

    private function searchEvents(array $args): array {
        $query    = trim((string)($args['query'] ?? ''));
        $daysBack = max(1, min(30, (int)($args['days_back'] ?? 30)));

        // Try structured events table first
        $events = $this->eventRepo->searchEvents($query, $daysBack);

        if (!$events) {
            // Fallback: FULLTEXT search in raw messages.
            // Expand query with common Russian synonyms for better recall.
            $expandedQuery = $this->expandEventQuery($query);
            $msgs = $this->msgRepo->searchFulltext($expandedQuery, $daysBack, 20);

            // Filter out short messages / user questions — keep only announcement-like content
            $msgs = array_values(array_filter($msgs, function (array $m): bool {
                $text = trim((string)($m['text'] ?? ''));
                if (mb_strlen($text) < 80) return false;             // too short = user question
                if (preg_match('/^\s*[^.!]*\?\s*$/u', $text)) return false; // pure question
                return true;
            }));

            $events = array_map(fn($m) => [
                'event_date'  => substr((string)($m['received_at'] ?? ''), 0, 10),
                'title'       => mb_substr(trim((string)($m['text'] ?? '')), 0, 120),
                'description' => trim((string)($m['text'] ?? '')),
            ], array_slice($msgs, 0, 8));
        }

        return ['events' => $events, 'count' => count($events)];
    }

    private function expandEventQuery(string $query): string {
        $synonyms = [
            'фильм'   => 'фильм кино кинотеатр показ',
            'кино'    => 'кино фильм показ',
            'концерт' => 'концерт живая музыка live music',
            'музыка'  => 'музыка концерт live',
            'событи'  => 'событие мероприятие афиша анонс',
            'мероприя'=> 'мероприятие событие афиша',
            'афиш'    => 'афиша анонс события мероприятия',
        ];
        $lower = mb_strtolower($query);
        foreach ($synonyms as $stem => $expansion) {
            if (str_contains($lower, $stem)) {
                return $expansion;
            }
        }
        return $query . ' афиша анонс';
    }

    private function searchKnowledge(array $args, bool $authorized): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') return ['items' => []];
        $docs  = $this->knowledgeSvc->search($query, $authorized, 5);
        return ['items' => $docs, 'count' => count($docs)];
    }

    private function getWeeklySummary(array $args): array {
        $summaries = [];

        // Specific date requested (e.g. "за вчера" → "2026-05-10")
        $dateArg = trim((string)($args['date'] ?? ''));
        if ($dateArg !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg)) {
            $row = $this->dailyRepo->getByDay($dateArg);
            if (is_array($row) && trim((string)($row['summary_text'] ?? '')) !== '') {
                $summaries[] = ['date' => $dateArg, 'summary' => $row['summary_text']];
            }
            return ['summaries' => $summaries, 'count' => count($summaries)];
        }

        $days = max(1, min(7, (int)($args['days_back'] ?? 7)));
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $row = $this->dailyRepo->getByDay($day);
            if (is_array($row) && trim((string)($row['summary_text'] ?? '')) !== '') {
                $summaries[] = ['date' => $day, 'summary' => $row['summary_text']];
            }
        }
        return ['summaries' => $summaries, 'count' => count($summaries)];
    }
}
