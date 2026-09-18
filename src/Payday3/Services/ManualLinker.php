<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Database;

/**
 * Cartesian "link every selected left row with every selected right row"
 * loop shared by the three 🎯 manual-link endpoints (IN checks, OUT mail,
 * income finance) — previously copy-pasted three times, two of which
 * swallowed every \Throwable and reported "added 0" with 200 OK on a DB
 * outage.
 *
 * All pairs run in ONE transaction (one fsync instead of N). A failing
 * pair doesn't abort the rest: validation/business errors are reported
 * with their message, infrastructure errors are logged and reported with
 * a generic text — either way the caller gets an `errors` list instead
 * of silence.
 */
final class ManualLinker
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param list<int>               $leftIds
     * @param list<int>               $rightIds
     * @param callable(int,int): void $linkOne
     * @return array{added:int, errors:list<string>}
     */
    public function link(array $leftIds, array $rightIds, callable $linkOne): array
    {
        return $this->db->transaction(static function () use ($leftIds, $rightIds, $linkOne): array {
            $added  = 0;
            $errors = [];
            foreach ($leftIds as $l) {
                foreach ($rightIds as $r) {
                    try {
                        $linkOne($l, $r);
                        $added++;
                    } catch (\InvalidArgumentException | \DomainException $e) {
                        $errors[] = sprintf('%d × %d: %s', $l, $r, $e->getMessage());
                    } catch (\Throwable $e) {
                        error_log(sprintf('[payday3.manual_link] %d x %d: %s: %s', $l, $r, get_class($e), $e->getMessage()));
                        $errors[] = sprintf('%d × %d: ошибка сохранения связи', $l, $r);
                    }
                }
            }
            return ['added' => $added, 'errors' => $errors];
        });
    }

    /**
     * Positive-int id list from a request body field (array or scalar).
     *
     * @return list<int>
     */
    public static function ids(mixed $raw): array
    {
        return array_values(array_filter(
            array_map('intval', (array)($raw ?? [])),
            static fn(int $i) => $i > 0,
        ));
    }
}
