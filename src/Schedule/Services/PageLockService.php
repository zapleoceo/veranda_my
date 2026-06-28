<?php

declare(strict_types=1);

namespace App\Schedule\Services;

use App\Schedule\Repositories\MetaCache;

/**
 * Single-editor lock for the /schedule page.
 *
 * Stored as JSON in system_meta with a 60-second TTL on the read side
 * (MetaCache::get returns null after that). The owning browser pings
 * heartbeat() every 20 seconds; each heartbeat refreshes the row's
 * updated_at, keeping the TTL window alive. If the owner closes the
 * tab (or crashes), heartbeats stop and the lock auto-frees after
 * ≤60 seconds — no manual cleanup required.
 *
 * `isOwner()` is the gate write-actions consult before persisting:
 * non-owners get a 423 Locked response and their edits are discarded.
 */
final class PageLockService
{
    private const KEY         = 'schedule_page_lock';
    public  const TTL_SECONDS = 60;   // public — JS uses it for heartbeat cadence

    public function __construct(private readonly MetaCache $cache) {}

    /**
     * Returns the currently-held lock (within TTL) or null when free.
     * Shape: ['email' => …, 'name' => …, 'acquired_at' => 'Y-m-d H:i:s'].
     */
    public function current(): ?array
    {
        $raw = $this->cache->get(self::KEY, self::TTL_SECONDS);
        return is_array($raw) ? $raw : null;
    }

    /**
     * Claim the lock if it's free OR already mine. If someone else holds
     * an active lock, returns `owned=false` with their info so the UI
     * can show a banner.
     */
    public function acquire(string $email, string $name): array
    {
        $current = $this->current();
        if ($current && (string) ($current['email'] ?? '') !== $email) {
            return ['owned' => false, 'lock' => $current];
        }
        // Preserve original acquired_at on heartbeat / re-acquire.
        $acquiredAt = $current['acquired_at'] ?? date('Y-m-d H:i:s');
        $this->cache->set(self::KEY, [
            'email'       => $email,
            'name'        => $name,
            'acquired_at' => $acquiredAt,
        ]);
        return ['owned' => true, 'lock' => $this->current()];
    }

    /** Same wire shape as acquire — refreshes updated_at if still mine. */
    public function heartbeat(string $email, string $name): array
    {
        return $this->acquire($email, $name);
    }

    /**
     * Force-take the lock regardless of who currently holds it.
     *
     * For the "Перехватить" button: someone left a tab open and their
     * heartbeat keeps the lock alive, so it never frees on its own. Edits
     * auto-save to the draft continuously and saves are version-guarded, so
     * the previous holder loses at most the last sub-second of typing; on
     * their next heartbeat they cleanly drop to read-only.
     * acquired_at resets to now — it's a fresh session for the new owner.
     */
    public function steal(string $email, string $name): array
    {
        $this->cache->set(self::KEY, [
            'email'       => $email,
            'name'        => $name,
            'acquired_at' => date('Y-m-d H:i:s'),
        ]);
        return ['owned' => true, 'lock' => $this->current()];
    }

    /** Explicit release — called from beforeunload via sendBeacon. */
    public function release(string $email): void
    {
        $current = $this->current();
        if ($current && (string) ($current['email'] ?? '') === $email) {
            $this->cache->purge([self::KEY]);
        }
    }

    public function isOwner(string $email): bool
    {
        if ($email === '') return false;
        $current = $this->current();
        return $current !== null && (string) ($current['email'] ?? '') === $email;
    }

    /**
     * Write-guard used by every save/mutate Action.
     *
     * Returns null when the caller may write — either they already own the
     * lock, OR it's free (no live holder) and we (re)claim it for them here.
     * Returns the blocking holder's record ONLY when someone ELSE actively
     * holds the lock (within TTL).
     *
     * Why this exists: previously a write simply checked isOwner() and, on
     * false, returned the current lock. But if YOUR OWN lock had TTL-expired
     * (a heartbeat gap — tab backgrounded, laptop asleep) the lock was free,
     * current() was null, and the client got "redactiruet null" → the banner
     * said «редактирует другой пользователь» with no name, even though nobody
     * was editing. Reclaiming a free lock here makes that impossible: a
     * blocked write now always names a real, live holder.
     */
    public function claimForWrite(string $email, string $name): ?array
    {
        $current = $this->current();
        // Held by a different, live operator → block and name them.
        if ($current !== null && (string) ($current['email'] ?? '') !== $email) {
            return $current;
        }
        // Free, or already ours → (re)claim for this actor and allow.
        // (These routes sit behind AuthMiddleware, so $email is always set.)
        if ($email !== '') {
            $this->acquire($email, $name);
        }
        return null;
    }
}
