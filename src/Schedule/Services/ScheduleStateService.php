<?php

declare(strict_types=1);

namespace App\Schedule\Services;

use App\Schedule\Contracts\EmployeeRateRepositoryInterface;
use App\Schedule\Contracts\EmployeesProviderInterface;
use App\Schedule\Contracts\HallsProviderInterface;
use App\Schedule\Contracts\SnapshotRepositoryInterface;
use App\Schedule\Contracts\StaffTagRepositoryInterface;
use App\Schedule\Contracts\ZoneRepositoryInterface;
use App\Schedule\Domain\DefaultState;

/**
 * High-level façade for everything the schedule UI needs.
 *
 * Has zero direct DB access — it composes repositories + providers. Tests
 * substitute in-memory fakes through the constructor.
 */
final class ScheduleStateService
{
    public function __construct(
        private readonly SnapshotRepositoryInterface     $snapshots,
        private readonly ZoneRepositoryInterface         $zones,
        private readonly StaffTagRepositoryInterface     $staffTags,
        private readonly EmployeeRateRepositoryInterface $rates,
        private readonly EmployeesProviderInterface      $employees,
        private readonly HallsProviderInterface          $halls,
    ) {}

    // ─── State (snapshots) ──────────────────────────────────────────

    /** Latest snapshot, or DefaultState if table is empty. */
    public function loadCurrent(): array
    {
        $raw = $this->snapshots->loadCurrent();
        if (!is_array($raw)) return DefaultState::make();
        // Ensure required keys (older snapshots may miss some)
        $defaults = DefaultState::make();
        $raw['version']   ??= 1;
        $raw['blocks']    ??= $defaults['blocks'];
        $raw['shifts']    ??= [];
        $raw['templates'] ??= $defaults['templates'];
        // Rules are new — seed defaults on legacy snapshots so the rule
        // engine has something to evaluate on first load after upgrade.
        if (empty($raw['rules']) || !is_array($raw['rules'])) {
            $raw['rules'] = $defaults['rules'];
        }
        return $raw;
    }

    /** Auto-save / regular save — overwrites the single draft row. */
    public function saveCurrent(array $state, string $email): int
    {
        return $this->snapshots->saveCurrent($state, $email);
    }

    /** Manual "Save as version" — creates a new named snapshot row. */
    public function saveNamedVersion(array $state, string $label, string $email): int
    {
        return $this->snapshots->saveNamedVersion($state, $label, $email);
    }

    public function renameSnapshot(int $id, string $label): bool
    {
        return $this->snapshots->rename($id, $label);
    }

    public function listSnapshots(int $limit = 25): array
    {
        return $this->snapshots->listRecent($limit);
    }

    public function loadSnapshot(int $id): ?array
    {
        return $this->snapshots->loadById($id);
    }

    /** For the public /schedule/v/{code} route — no auth required. */
    public function loadByShareCode(string $code): ?array
    {
        return $this->snapshots->loadByShareCode($code);
    }

    public function deleteSnapshot(int $id): bool
    {
        return $this->snapshots->delete($id);
    }

    // ─── Zones ─────────────────────────────────────────────────────

    public function listZones(): array
    {
        return $this->zones->listActive();
    }

    public function addZone(string $name, string $icon = '🌿'): int
    {
        return $this->zones->add($name, $icon);
    }

    public function deleteZone(int $id): void
    {
        $this->zones->softDelete($id);
    }

    // ─── Staff tags + rate ─────────────────────────────────────────

    /**
     * Saves schedule-only flags via StaffTagRepository AND the hourly rate
     * via the canonical EmployeeRateRepository (the same table the
     * /employees/ page writes to). Splitting the persistence avoids
     * duplicating the rate in two places.
     */
    public function saveStaffTag(int $userId, array $tag): void
    {
        $this->staffTags->save($userId, $tag);
        $rate = (int) ($tag['rate_per_hour'] ?? 0);
        $by   = (string) ($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? '');
        $this->rates->save($userId, $rate, $by !== '' ? $by : null);
    }

    // ─── Providers (Poster passthrough) ────────────────────────────

    public function fetchEmployees(): array
    {
        return $this->employees->fetch();
    }

    public function fetchHalls(): array
    {
        return $this->halls->fetch();
    }

    public function purgePosterCache(): void
    {
        $this->employees->purgeCache();
        $this->halls->purgeCache();
    }
}
