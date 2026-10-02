<?php

declare(strict_types=1);

namespace Tests\Unit\Afisha;

use App\Afisha\AfishaPlan;
use App\Afisha\AfishaStore;
use PHPUnit\Framework\TestCase;

/**
 * Афиша сайта обновляется по ответу ИИ на сообщение из открытой группы —
 * значит, ответу нельзя доверять. Эти тесты держат границу: что принимаем,
 * что режем, и что откат действительно возвращает прежнее состояние.
 */
class AfishaPlanTest extends TestCase
{
    private \DateTimeImmutable $today;
    private string $monday;

    protected function setUp(): void
    {
        // Пятница 2 октября 2026 — неделя с понедельника 28 сентября.
        $this->today = new \DateTimeImmutable('2026-10-02 12:00', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $this->monday = '2026-09-28';
    }

    public function test_irrelevant_message_is_not_a_plan(): void
    {
        $this->assertNull(AfishaPlan::fromAi(['relevant' => false, 'kind' => 'none', 'days' => []], $this->today));
        $this->assertNull(AfishaPlan::fromAi(['relevant' => true, 'kind' => 'none', 'days' => []], $this->today));
    }

    public function test_any_date_of_the_week_is_normalized_to_its_monday(): void
    {
        $plan = AfishaPlan::fromAi($this->ai('2026-10-01'), $this->today);
        $this->assertSame($this->monday, $plan['week_start']);
    }

    public function test_next_week_is_accepted_for_a_sunday_announcement(): void
    {
        $this->assertSame('2026-10-05', AfishaPlan::fromAi($this->ai('2026-10-05'), $this->today)['week_start']);
    }

    public function test_past_week_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AfishaPlan::fromAi($this->ai('2026-09-21'), $this->today);
    }

    public function test_far_future_week_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AfishaPlan::fromAi($this->ai('2026-12-07'), $this->today);
    }

    public function test_plan_without_usable_days_is_rejected(): void
    {
        $ai = $this->ai($this->monday);
        $ai['days'] = [['weekday' => 9, 'type' => 'music', 'ru' => ['title' => 'x', 'time' => '', 'note' => '']]];
        $this->expectException(\InvalidArgumentException::class);
        AfishaPlan::fromAi($ai, $this->today);
    }

    public function test_markup_is_stripped_and_long_text_is_cut(): void
    {
        $ai = $this->ai($this->monday);
        $ai['days'][0]['type'] = 'hack';
        $ai['days'][0]['ru'] = ['title' => '<script>alert(1)</script>Джаз  <b>вечер</b>', 'time' => '20:00', 'note' => str_repeat('я', 500)];
        $ai['days'][0]['en'] = ['title' => '', 'time' => '', 'note' => ''];

        $card = AfishaPlan::fromAi($ai, $this->today)['days'][5];

        $this->assertSame('alert(1)Джаз вечер', $card['ru']['title']);
        $this->assertSame('other', $card['type']);
        $this->assertLessThanOrEqual(220, mb_strlen($card['ru']['note']));
        $this->assertSame($card['ru'], $card['en'], 'пустой перевод заменяется русским текстом');
    }

    public function test_patch_touches_only_its_days_and_full_replaces_the_week(): void
    {
        $existing = [1 => ['type' => 'chill'], 5 => ['type' => 'music'], 6 => ['type' => 'music']];
        $new = [5 => ['type' => 'film']];

        $this->assertSame([1 => ['type' => 'chill'], 5 => ['type' => 'film'], 6 => ['type' => 'music']], AfishaPlan::merge($existing, $new, 'patch'));
        $this->assertSame($new, AfishaPlan::merge($existing, $new, 'full'));
    }

    public function test_store_undo_restores_previous_version_once(): void
    {
        $store = new AfishaStore(sys_get_temp_dir() . '/afisha-phpunit-' . bin2hex(random_bytes(4)));
        $v1 = AfishaPlan::fromAi($this->ai($this->monday), $this->today)['days'];

        $this->assertSame([], $store->cardsFor($this->monday, 'ru'));

        $store->save($this->monday, ['days' => $v1]);
        $this->assertSame('Live Music', $store->cardsFor($this->monday, 'ru')[5]['title']);
        $this->assertSame('Nhạc sống', $store->cardsFor($this->monday, 'vi')[5]['title']);

        $v2 = $v1;
        $v2[5]['ru']['title'] = 'Джаз';
        $store->save($this->monday, ['days' => $v2]);
        $this->assertSame('Джаз', $store->cardsFor($this->monday, 'ru')[5]['title']);

        $this->assertTrue($store->undo($this->monday));
        $this->assertSame('Live Music', $store->cardsFor($this->monday, 'ru')[5]['title']);
        $this->assertFalse($store->undo($this->monday), 'откат одноразовый');
    }

    public function test_undo_of_the_first_version_falls_back_to_default_schedule(): void
    {
        $store = new AfishaStore(sys_get_temp_dir() . '/afisha-phpunit-' . bin2hex(random_bytes(4)));
        $store->save($this->monday, ['days' => AfishaPlan::fromAi($this->ai($this->monday), $this->today)['days']]);

        $this->assertTrue($store->undo($this->monday));
        $this->assertSame([], $store->cardsFor($this->monday, 'ru'));
    }

    public function test_webhook_redelivery_is_seen_once_but_an_edit_is_new(): void
    {
        $store = new AfishaStore(sys_get_temp_dir() . '/afisha-phpunit-' . bin2hex(random_bytes(4)));

        $this->assertTrue($store->markSeen('6729:0'));
        $this->assertFalse($store->markSeen('6729:0'));
        $this->assertTrue($store->markSeen('6729:1790521000'));
    }

    /** @return array<string,mixed> */
    private function ai(string $weekStart): array
    {
        return [
            'relevant' => true,
            'kind' => 'full',
            'week_start' => $weekStart,
            'summary_ru' => 'Афиша на неделю',
            'days' => [[
                'weekday' => 5,
                'type' => 'music',
                'ru' => ['title' => 'Live Music', 'sessions' => [['time' => '19:00', 'text' => 'живая музыка']], 'note' => 'Уют, звёзды и музыка'],
                'en' => ['title' => 'Live Music', 'sessions' => [['time' => '19:00', 'text' => 'live music']], 'note' => 'Cosy evening under the stars'],
                'vi' => ['title' => 'Nhạc sống', 'sessions' => [['time' => '19:00', 'text' => 'nhạc sống']], 'note' => 'Buổi tối ấm cúng'],
            ]],
        ];
    }

    public function test_each_event_gets_its_own_line_with_its_start_time(): void
    {
        // «18:00 · 20:00» одной строкой читалось как промежуток — это два начала.
        $lines = AfishaPlan::lines(['title' => 'Кино', 'note' => 'Кино под открытым небом', 'sessions' => [
            ['time' => '18:00', 'text' => '«Тайна зубных фей» (2025), детский сеанс'],
            ['time' => '20:00', 'text' => '«Апгрейд» (2018)'],
        ]]);

        $this->assertSame(['18:00 — «Тайна зубных фей» (2025), детский сеанс', '20:00 — «Апгрейд» (2018)'], $lines);
    }

    public function test_day_without_timed_events_shows_its_description(): void
    {
        $this->assertSame(['Отдыхаем и наслаждаемся атмосферой'], AfishaPlan::lines(['title' => 'Chill Day', 'sessions' => [], 'note' => 'Отдыхаем и наслаждаемся атмосферой']));
    }

    public function test_default_schedule_is_split_into_start_times_too(): void
    {
        $this->assertSame(
            ['18:00 — детский сеанс', '20:00 — взрослый сеанс'],
            AfishaPlan::legacyLines('18:00 · 20:00', '18:00 — детский сеанс · 20:00 — взрослый сеанс')
        );
        $this->assertSame(['19:00 — Каверы англоязычных хитов'], AfishaPlan::legacyLines('19:00', 'Каверы англоязычных хитов'));
        $this->assertSame(['весь вечер — Бункер, Мафия, Uno'], AfishaPlan::legacyLines('весь вечер', 'Бункер, Мафия, Uno'));
    }

    public function test_unknown_performer_leaves_only_the_start_time(): void
    {
        // Не «группы чередуются»: исполнитель не известен — пишем только время.
        $ai = $this->ai($this->monday);
        $ai['days'][0]['ru']['sessions'] = [['time' => '19:00', 'text' => '']];

        $ru = AfishaPlan::fromAi($ai, $this->today)['days'][5]['ru'];

        $this->assertSame(['19:00'], AfishaPlan::lines($ru));
    }

    public function test_default_music_days_name_no_rotating_bands(): void
    {
        $this->assertSame(['19:00 — Рядновы'], AfishaPlan::legacyLines('19:00', 'Рядновы'));
        $this->assertSame(['19:00'], AfishaPlan::legacyLines('19:00', ''));
    }

    public function test_sessions_are_sanitized_and_capped(): void
    {
        $ai = $this->ai($this->monday);
        $ai['days'][0]['ru']['sessions'] = array_fill(0, 20, ['time' => '19:00', 'text' => '<b>Джаз</b>']);
        $ai['days'][0]['ru']['sessions'][] = 'мусор';

        $sessions = AfishaPlan::fromAi($ai, $this->today)['days'][5]['ru']['sessions'];

        $this->assertCount(6, $sessions);
        $this->assertSame(['time' => '19:00', 'text' => 'Джаз'], $sessions[0]);
    }
}
