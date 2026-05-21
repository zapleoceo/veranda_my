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

    /**
     * Latest draft, or DefaultState if table is empty. Returns:
     *   ['state' => array, 'version' => int]
     * The `version` counter is what the client should send back on the
     * next saveCurrent for optimistic-concurrency checks.
     */
    public function loadCurrent(): array
    {
        $row = $this->snapshots->loadCurrent();
        if ($row === null) {
            return ['state' => DefaultState::make(), 'version' => 0];
        }
        $state = $this->normalizeState($row['state'] ?? []);
        return ['state' => $state, 'version' => (int) ($row['version'] ?? 0)];
    }

    /** Fills missing top-level keys with DefaultState values. */
    private function normalizeState(array $state): array
    {
        $defaults = DefaultState::make();
        // Note: state['version'] is the snapshot's own "schema version"
        // (used by old DefaultState). NOT to be confused with the row's
        // optimistic-concurrency `version` returned alongside in
        // loadCurrent's wrapper.
        $state['version']   ??= 1;
        $state['blocks']    ??= $defaults['blocks'];
        $state['shifts']    ??= [];
        $state['templates'] ??= $defaults['templates'];
        // Rules: only seed when key MISSING. Empty array preserved.
        if (!array_key_exists('rules', $state) || !is_array($state['rules'])) {
            $state['rules'] = $defaults['rules'];
        }
        return $state;
    }

    /**
     * Auto-save / regular save — UPDATEs the single draft row.
     * Returns ['id' => N, 'version' => N+1] on success, or
     * ['conflict' => true, 'version' => N, 'state' => …] when a
     * concurrent save by another operator beat us to it.
     */
    public function saveCurrent(array $state, string $email, ?int $expectedVersion = null): array
    {
        $result = $this->snapshots->saveCurrent($state, $email, $expectedVersion);
        if (!empty($result['conflict']) && is_array($result['state'] ?? null)) {
            $result['state'] = $this->normalizeState($result['state']);
        }
        return $result;
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
     * Saves schedule-only flags via StaffTagRepository.
     *
     * Hourly rate is NOT written here even though the modal shows a
     * ₫/ч column — rates are owned by the /employees/ page and stored
     * in `employee_rates`. The schedule modal displays the current
     * value read-only-ish; if the user types a new number it's
     * deliberately ignored so the two UIs never disagree.
     *
     * `$actorEmail` is passed in explicitly so the service stays free of
     * HTTP/session context (Single Responsibility — the Action knows about
     * $_SESSION, the service doesn't).
     */
    public function saveStaffTag(int $userId, array $tag, string $actorEmail = ''): void
    {
        $this->staffTags->save($userId, $tag);
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
