<?php

declare(strict_types=1);

namespace App\Schedule\Services;

use App\Classes\PosterAPI;
use App\Schedule\Contracts\EmployeeRateRepositoryInterface;
use App\Schedule\Contracts\EmployeesProviderInterface;
use App\Schedule\Contracts\StaffTagRepositoryInterface;
use App\Schedule\Repositories\MetaCache;

/**
 * Roster source for the schedule UI: Poster `access.getEmployees` (cached
 * in system_meta for 30 minutes) overlaid with:
 *   • staff tags  — schedule-only flags  (in_schedule, can_be_senior, …)
 *   • hourly rate — shared `employee_rates` table (same canonical store
 *                   the /employees/ page reads and writes)
 *
 * Single responsibility: compose the live roster the schedule UI needs.
 * Knows nothing about HTTP, views, or state shape beyond the contract.
 */
final class PosterEmployeesProvider implements EmployeesProviderInterface
{
    // Cache key bumped to v2 — adds the dismissed-employee filter, so any
    // stale v1 cache (which may contain fired employees) is ignored on first
    // hit after deploy.
    private const CACHE_KEY     = 'schedule_poster_employees_v2';
    private const CACHE_SECONDS = 1800;  // 30 минут

    public function __construct(
        private readonly StaffTagRepositoryInterface     $tags,
        private readonly EmployeeRateRepositoryInterface $rates,
        private readonly MetaCache $cache,
        private readonly string $posterToken,
    ) {}

    public function fetch(): array
    {
        $rows = $this->cache->get(self::CACHE_KEY, self::CACHE_SECONDS);
        if (is_array($rows)) return $this->overlay($rows);

        if ($this->posterToken !== '') {
            try {
                $api = new PosterAPI($this->posterToken);
                $raw = $api->request('access.getEmployees', [], 'GET');
                if (is_array($raw)) {
                    $rows = $this->normalize($raw);
                    if (!empty($rows)) {
                        $this->cache->set(self::CACHE_KEY, $rows);
                        return $this->overlay($rows);
                    }
                }
            } catch (\Throwable) {
                // fall through to hardcoded
            }
        }
        return $this->overlay($this->hardcoded());
    }

    public function purgeCache(): void
    {
        $this->cache->purge([self::CACHE_KEY]);
    }

    private function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $r) {
            if (!is_array($r)) continue;
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid <= 0) continue;
            if (self::isDismissed($r)) continue;
            $out[] = [
                'id'             => $uid,
                'name'           => trim((string) ($r['name'] ?? '')),
                'poster_role'    => (string) ($r['role_name'] ?? ''),
                'tag'            => (string) ($r['role_name'] ?? ''),
                'in_schedule'    => true,
                'can_be_senior'  => false,
                'only_in_blocks' => '',
                'rate_per_hour'  => 0,
            ];
        }
        return $out;
    }

    /**
     * Heuristic dismissed/fired filter.
     *
     * The public v3 docs (https://dev.joinposter.com/docs/v3/web/access/getEmployees)
     * only describe user_id / name / role_id / role_name / phone / access_mask /
     * user_type / last_in. They do NOT document any dismissed/deleted indicator.
     *
     * In practice the API quietly returns extra fields — the exact name varies
     * by account/region/version. We inspect every conventional spelling so a
     * fired employee gets dropped regardless of which one Poster sends back:
     *
     *   • boolean-ish flags: dismissed, deleted, fired, is_dismissed,
     *     is_deleted, is_fired
     *   • inverse flags:    user_active, active, is_active, enabled  (== 0 ⇒ off)
     *   • date stamps:      dismiss_date, dismissed_at, deleted_at, fired_at
     *   • status strings:   status / user_status == dismissed|deleted|fired|inactive
     *
     * If Poster adds a new field in the future, extend this method — every other
     * layer (overlay, cache, UI) is agnostic.
     */
    private static function isDismissed(array $row): bool
    {
        // Boolean-ish "is fired" flags — truthy means dismissed.
        foreach (['dismissed', 'deleted', 'fired', 'is_dismissed', 'is_deleted', 'is_fired'] as $k) {
            if (array_key_exists($k, $row) && self::truthy($row[$k])) return true;
        }
        // Inverse boolean flags — falsy (0/false/"0") means dismissed.
        foreach (['user_active', 'active', 'is_active', 'enabled'] as $k) {
            if (array_key_exists($k, $row) && !self::truthy($row[$k])) return true;
        }
        // Date stamps — non-empty + non-"0000-00-00" means a dismissal date is set.
        foreach (['dismiss_date', 'dismissed_at', 'deleted_at', 'fired_at'] as $k) {
            $v = $row[$k] ?? null;
            if (is_string($v) && trim($v) !== '' && !str_starts_with($v, '0000-00-00')) return true;
        }
        // Status strings.
        foreach (['status', 'user_status'] as $k) {
            $v = isset($row[$k]) ? strtolower(trim((string) $row[$k])) : '';
            if (in_array($v, ['dismissed', 'deleted', 'fired', 'inactive'], true)) return true;
        }
        return false;
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v))   return $v;
        if (is_int($v))    return $v !== 0;
        if (is_string($v)) return !in_array(strtolower(trim($v)), ['', '0', 'false', 'no'], true);
        return (bool) $v;
    }

    private function overlay(array $employees): array
    {
        $tags  = $this->tags->all();
        $rates = $this->rates->all();
        foreach ($employees as &$e) {
            $uid = (int) $e['id'];
            // Hourly rate — canonical store, shared with /employees/.
            $e['rate_per_hour'] = (int) ($rates[$uid] ?? 0);
            // Schedule-only flags.
            $t = $tags[$uid] ?? null;
            if (!$t) continue;
            $e['in_schedule']    = $t['in_schedule'];
            $e['can_be_senior']  = $t['can_be_senior'];
            $e['only_in_blocks'] = $t['only_in_blocks'];
            if ($t['custom_tag'] !== '') $e['tag'] = $t['custom_tag'];
        }
        unset($e);
        return $employees;
    }

    /**
     * Demo fallback (no Poster token, or API failed). Mirrors the original
     * mockup roster so the page never looks empty on dev/staging.
     */
    private function hardcoded(): array
    {
        return [
            ['id' => 5,  'name' => 'Султан', 'poster_role' => 'bartender', 'tag' => 'Бар',      'in_schedule' => true, 'can_be_senior' => true,  'only_in_blocks' => '', 'rate_per_hour' => 65000],
            ['id' => 7,  'name' => 'Оля',    'poster_role' => 'host',      'tag' => 'Хост',     'in_schedule' => true, 'can_be_senior' => false, 'only_in_blocks' => '', 'rate_per_hour' => 55000],
            ['id' => 12, 'name' => 'Лёша',   'poster_role' => 'host',      'tag' => 'Хост',     'in_schedule' => true, 'can_be_senior' => true,  'only_in_blocks' => '', 'rate_per_hour' => 60000],
            ['id' => 18, 'name' => 'Phai',   'poster_role' => 'waiter',    'tag' => 'Официант', 'in_schedule' => true, 'can_be_senior' => true,  'only_in_blocks' => '', 'rate_per_hour' => 50000],
            ['id' => 19, 'name' => 'Long',   'poster_role' => 'waiter',    'tag' => 'Официант', 'in_schedule' => true, 'can_be_senior' => false, 'only_in_blocks' => '', 'rate_per_hour' => 48000],
            ['id' => 22, 'name' => 'An',     'poster_role' => 'waiter',    'tag' => 'Баня',     'in_schedule' => true, 'can_be_senior' => false, 'only_in_blocks' => '', 'rate_per_hour' => 55000],
            ['id' => 25, 'name' => 'Саша',   'poster_role' => 'waiter',    'tag' => 'Официант', 'in_schedule' => true, 'can_be_senior' => false, 'only_in_blocks' => '', 'rate_per_hour' => 52000],
            ['id' => 26, 'name' => 'Вася',   'poster_role' => 'waiter',    'tag' => 'Официант', 'in_schedule' => true, 'can_be_senior' => false, 'only_in_blocks' => '', 'rate_per_hour' => 50000],
        ];
    }
}
