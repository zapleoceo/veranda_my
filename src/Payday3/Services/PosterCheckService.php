<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\AuditLogInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterCheckServiceInterface;
use App\Payday3\Contracts\SessionStoreInterface;
use App\Payday3\Contracts\TelegramNotifierInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;
use App\Payday3\Domain\PosterIds;
use App\Payday3\Domain\PosterTime;

/**
 * Two operations on Poster checks:
 *
 *   find(transactionId, range)
 *     Paginated walk over transactions.getTransactions (per_page = 1000,
 *     hard cap 50 pages) looking for an exact transaction_id match.
 *
 *   remove(transactionId, byLabel, userEmail)
 *     Fetches the check first (dash.getTransaction) and refuses unless it
 *     was closed within the last MAX_AGE_DAYS days; writes an audit row
 *     to payday_audit_log, then calls transactions.removeTransaction with
 *     the service-user id from local settings; on success, fires a
 *     Telegram note to the configured chat/thread.
 *
 * No payday2 imports anywhere — service-user, chat-id, thread-id all
 * come from the injected LocalSettings repository.
 */
final class PosterCheckService implements PosterCheckServiceInterface
{
    /** A check can be deleted only if it was closed today or up to N days ago. */
    public const MAX_AGE_DAYS = 3;

    private const TABLES_CACHE_KEY   = 'pd3_tables_cache';
    private const PRODUCTS_CACHE_KEY = 'pd3_products_cache';
    private const CACHE_TTL_S        = 6 * 3600;

    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly TelegramNotifierInterface        $tg,
        private readonly LocalSettingsRepositoryInterface $settings,
        private readonly SessionStoreInterface            $session,
        private readonly AuditLogInterface                $audit,
    ) {}

    public function find(int $transactionId, DateRange $range): array
    {
        if ($transactionId <= 0) {
            throw new \InvalidArgumentException('Invalid transaction_id');
        }
        $api  = $this->poster->client();
        $page = 1;
        $per  = 1000;
        $maxPages = 50;
        while ($page <= $maxPages) {
            $resp = $api->request('transactions.getTransactions', [
                'date_from' => $range->from,
                'date_to'   => $range->to,
                'per_page'  => $per,
                'page'      => $page,
            ], 'GET');
            $rows = is_array($resp) ? ($resp['data'] ?? []) : [];
            if (!is_array($rows) || $rows === []) break;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                if ((int)($row['transaction_id'] ?? 0) === $transactionId) {
                    return [
                        'found'       => true,
                        'transaction' => self::normaliseTransactionMoney($row),
                        'products'    => is_array($row['products'] ?? null)
                                            ? self::normaliseProductsMoney($row['products'])
                                            : [],
                    ];
                }
            }
            if (count($rows) < $per) break;
            $page++;
        }
        return ['found' => false];
    }

    public function listRecent(DateRange $range, int $limit = 200): array
    {
        if ($limit <= 0) $limit = 200;
        $api = $this->poster->client();

        // dash.getTransactions returns open/closed/deleted checks in
        // one shot WITH their products inline — exactly what payday2's
        // ?ajax=poster_checks_list relied on for the expand-on-click
        // feature. Falls back to the v3 paginated endpoint when the
        // dash flavour is unavailable on this Poster tenant.
        try {
            $rows = $api->request('dash.getTransactions', [
                'dateFrom'         => str_replace('-', '', $range->from),
                'dateTo'           => str_replace('-', '', $range->to),
                'include_products' => 'true',
                'status'           => 0,
            ], 'GET');
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!is_array($rows) || $rows === []) {
            return $this->listRecentV3Fallback($range, $limit);
        }

        $names = $this->productNameMap($api);

        // Collect spots referenced by the check set so we can fetch
        // their tables once and resolve table_title for the table_id
        // column.
        $spotIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $sid = (int)($row['spot_id'] ?? $row['spotId'] ?? 1);
            if ($sid > 0) $spotIds[$sid] = true;
        }
        $tableTitles = $this->tableTitleMap($api, array_keys($spotIds));

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $txId = (int)($row['transaction_id'] ?? $row['id'] ?? 0);
            if ($txId <= 0) continue;

            // Status: 1=open, 2=closed, 3=deleted — derive when Poster
            // omits the explicit field (legacy responses).
            $status = (int)($row['status'] ?? $row['transaction_status'] ?? 0);
            if ($status <= 0) {
                if ((int)($row['delete'] ?? 0) === 1)              $status = 3;
                elseif ((string)($row['date_close'] ?? '') !== '') $status = 2;
                else                                               $status = 1;
            }

            // Build a per-product line with VND-converted prices.
            // `num` is the quantity (decimal). Poster's per-product
            // sum field has been seen as product_sum / payed_sum /
            // sum / amount on different tenants — fall through the
            // list until we find one with a non-zero value.
            $productsOut = [];
            $productsIn  = is_array($row['products'] ?? null) ? $row['products'] : [];
            foreach ($productsIn as $pr) {
                if (!is_array($pr)) continue;
                $pid       = (int)($pr['product_id'] ?? 0);
                $qty       = (float)($pr['num'] ?? $pr['quantity'] ?? 0);
                $totalRaw  = $pr['product_sum']
                          ?? $pr['payed_sum']
                          ?? $pr['sum']
                          ?? $pr['amount']
                          ?? $pr['product_price_sum']
                          ?? 0;
                $totalVnd  = Money::posterMinorToVnd($totalRaw);
                // Catalog per-unit price falls back to product_price
                // (also in cents) when total is missing.
                $unitVnd   = $qty > 0 && $totalVnd > 0
                    ? (int)floor($totalVnd / $qty)
                    : Money::posterMinorToVnd($pr['product_price'] ?? 0);
                $productsOut[] = [
                    'product_id' => $pid,
                    'name'       => $pid > 0 ? ($names[$pid] ?? ('#' . $pid)) : '',
                    'qty'        => $qty,
                    'unit_price' => $unitVnd > 0 ? $unitVnd : null,
                    'total'      => $totalVnd > 0 ? $totalVnd : null,
                ];
            }

            $spotId    = (int)($row['spot_id'] ?? $row['spotId'] ?? 1);
            $tableId   = (int)($row['table_id'] ?? 0);
            // Prefer the title Poster sent inline; fall back to the
            // per-spot map we just built; raw id is the last resort.
            $tableTitle = trim((string)($row['table_title'] ?? $row['table_name'] ?? ''));
            if ($tableTitle === '' && $tableId > 0) {
                $tableTitle = $tableTitles[$spotId][$tableId] ?? '';
            }

            $out[] = [
                'transaction_id' => $txId,
                'receipt_number' => (int)($row['receipt_number'] ?? $txId),
                'date_close'     => (string)($row['date_close'] ?? $row['date_close_date'] ?? ''),
                'sum'            => Money::posterMinorToVnd($row['sum']       ?? 0),
                'payed_sum'      => Money::posterMinorToVnd($row['payed_sum'] ?? $row['sum'] ?? 0),
                'pay_type'       => (int)($row['pay_type'] ?? 0),
                'status'         => $status,
                'spot_id'        => $spotId,
                'table_id'       => $tableId,
                'table_title'    => $tableTitle,
                'waiter_name'    => (string)($row['waiter_name'] ?? $row['name'] ?? ''),
                'products'       => $productsOut,
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * Fallback when dash.getTransactions is unavailable on this Poster
     * tenant — original v3 paginated endpoint without products.
     */
    private function listRecentV3Fallback(DateRange $range, int $limit): array
    {
        $api  = $this->poster->client();
        $out  = [];
        $page = 1;
        $per  = min($limit, 1000);
        $maxPages = 5;
        while ($page <= $maxPages && count($out) < $limit) {
            $resp = $api->request('transactions.getTransactions', [
                'date_from' => $range->from,
                'date_to'   => $range->to,
                'per_page'  => $per,
                'page'      => $page,
            ], 'GET');
            $rows = is_array($resp) ? ($resp['data'] ?? []) : [];
            if (!is_array($rows) || $rows === []) break;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $sumRaw = $row['sum'] ?? $row['payed_sum'] ?? 0;
                $out[] = [
                    'transaction_id' => (int)($row['transaction_id'] ?? 0),
                    'receipt_number' => (int)($row['receipt_number'] ?? $row['transaction_id'] ?? 0),
                    'date_close'     => (string)($row['date_close'] ?? $row['date_close_date'] ?? ''),
                    // Money::toInt (бывш. vndFromV3), НЕ posterMinorToVnd: этот метод читает
                    // transactions.getTransactions, а он отдаёт донги строкой с
                    // копейками в дробной части ("495000.00" = 495 000 ₫), тогда
                    // как dash.* отдаёт минорные единицы (49500000). Деление на
                    // 100 здесь занижало чеки ровно в 100 раз: 495 000 ₫ → 4 950.
                    // Сверено на живом проде, чек 24821: dash=49500000,
                    // transactions.getTransactions="495000.00" — обе ветки должны
                    // давать 495000. То же самое уже чинили в find() (строка 60),
                    // до этой ветки правка не доехала.
                    'sum'            => Money::toInt($sumRaw),
                    'payed_sum'      => Money::toInt($sumRaw),
                    'pay_type'       => (int)($row['pay_type'] ?? 0),
                    'status'         => 2,
                    'spot_id'        => (int)($row['spot_id'] ?? 0),
                    'table_id'       => (int)($row['table_id'] ?? 0),
                    'waiter_name'    => (string)($row['waiter_name'] ?? $row['name'] ?? ''),
                    'products'       => [],
                ];
                if (count($out) >= $limit) break;
            }
            if (count($rows) < $per) break;
            $page++;
        }
        return $out;
    }

    /**
     * Build a {spot_id: {table_id: title}} map by walking each spot's
     * halls via spots.getSpotTablesHalls + spots.getTableHallTables.
     * Falls back to a flat spots.getTableHallTables call if a spot
     * exposes no halls. Cached in the session for 6 hours — same TTL
     * payday2 used. Without this map the Check Finder modal shows
     * raw table_id integers instead of the operator-friendly title
     * ("Бар 1", "ВИП", etc.).
     *
     * Errors are swallowed per-spot so one tenant's quirky API
     * response can't blank-out the entire map.
     *
     * @param array<int,int> $spotIds
     * @return array<int,array<int,string>>
     */
    private function tableTitleMap($api, array $spotIds): array
    {
        if ($spotIds === []) return [];
        // Session is touched only for the read and the final write — the
        // Poster calls below run with the session lock released.
        $cache = $this->session->get(self::TABLES_CACHE_KEY);
        $cacheTs    = is_array($cache) ? (int)($cache['ts']    ?? 0)    : 0;
        $cacheSpots = is_array($cache) ? ($cache['spots'] ?? null) : null;
        $spotMaps   = (is_array($cacheSpots) && $cacheTs > 0 && (time() - $cacheTs) < self::CACHE_TTL_S)
            ? $cacheSpots
            : [];

        $getTables = static function (array $params) use ($api) {
            try {
                return $api->request('spots.getTableHallTables', $params, 'GET');
            } catch (\Throwable $e) {
                if (stripos((string)$e->getMessage(), 'http=405') !== false) {
                    return $api->request('spots.getTableHallTables', $params, 'POST');
                }
                return [];
            }
        };
        $getHalls = static function (int $sId) use ($api) {
            try {
                return $api->request('spots.getSpotTablesHalls', ['spot_id' => $sId], 'GET');
            } catch (\Throwable $e) {
                if (stripos((string)$e->getMessage(), 'http=405') !== false) {
                    return $api->request('spots.getSpotTablesHalls', ['spot_id' => $sId], 'POST');
                }
                return [];
            }
        };

        $changed = false;
        foreach ($spotIds as $spotId) {
            $spotId = (int)$spotId;
            if ($spotId <= 0) $spotId = 1;
            if (isset($spotMaps[$spotId]) && is_array($spotMaps[$spotId]) && count($spotMaps[$spotId]) > 0) {
                continue;
            }
            $tables = [];
            $halls  = $getHalls($spotId);
            if (is_array($halls) && $halls !== []) {
                foreach ($halls as $hall) {
                    if (!is_array($hall)) continue;
                    $hallId = (int)($hall['hall_id'] ?? 0);
                    if ($hallId <= 0) continue;
                    $hallTables = $getTables(['spot_id' => $spotId, 'hall_id' => $hallId, 'without_deleted' => 1]);
                    if (is_array($hallTables)) {
                        $tables = array_merge($tables, $hallTables);
                    }
                }
            }
            if ($tables === []) {
                $fallback = $getTables(['spot_id' => $spotId, 'without_deleted' => 1]);
                if (is_array($fallback)) $tables = $fallback;
            }
            $map = [];
            foreach ($tables as $t) {
                if (!is_array($t)) continue;
                $tid = (int)($t['table_id'] ?? 0);
                $ttl = trim((string)($t['table_title'] ?? $t['table_name'] ?? ''));
                if ($tid > 0 && $ttl !== '') $map[$tid] = $ttl;
            }
            $spotMaps[$spotId] = $map;
            $changed = true;
        }
        if ($changed) {
            $this->session->set(self::TABLES_CACHE_KEY, ['ts' => time(), 'spots' => $spotMaps]);
        }
        return $spotMaps;
    }

    /**
     * id → product name map from menu.getProducts. Cached per session
     * for 6 hours — same TTL payday2 used. Without this, each row's
     * products would show as "#<id>" instead of human-readable
     * names.
     *
     * @return array<int,string>
     */
    private function productNameMap($api): array
    {
        $cache = $this->session->get(self::PRODUCTS_CACHE_KEY);
        if (is_array($cache) && (time() - (int)($cache['ts'] ?? 0)) < self::CACHE_TTL_S && is_array($cache['map'] ?? null)) {
            return $cache['map'];
        }
        try {
            $products = $api->request('menu.getProducts', [], 'GET');
        } catch (\Throwable $e) {
            return is_array($cache['map'] ?? null) ? $cache['map'] : [];
        }
        $map = [];
        if (is_array($products)) {
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $pid  = (int)($p['product_id'] ?? $p['id'] ?? 0);
                $name = trim((string)($p['product_name'] ?? $p['name'] ?? ''));
                if ($pid > 0 && $name !== '') $map[$pid] = $name;
            }
        }
        $this->session->set(self::PRODUCTS_CACHE_KEY, ['ts' => time(), 'map' => $map]);
        return $map;
    }

    /**
     * Normalise money fields on a transaction row returned by
     * transactions.getTransactions (v3 REST endpoint).
     *
     * Unlike dash.getTransactions (v2), the v3 endpoint returns money
     * as VND with decimal strings ("35000.00" for a 35 000 VND check),
     * not kopecks/cents. Money::posterMinorToVnd would divide by 100
     * again → we saw check 23672's 35 000 VND rendered as 350. So parse
     * the value as-is instead of running it through fromPosterCents().
     *
     * @param array<string,mixed> $row
     */
    private static function normaliseTransactionMoney(array $row): array
    {
        foreach (['sum', 'payed_sum', 'payed_cash', 'payed_card',
                  'payed_third_party', 'payed_cert', 'payed_bonus',
                  'tip_sum', 'discount', 'round_sum', 'pay_sum'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = Money::toInt($row[$k]);
            }
        }
        return $row;
    }

    /**
     * @param  array<int,mixed> $products
     * @return array<int,array>
     */
    private static function normaliseProductsMoney(array $products): array
    {
        return array_map(static function ($p) {
            if (!is_array($p)) return $p;
            foreach (['product_sum', 'payed_sum', 'unit_price', 'total', 'price'] as $k) {
                if (array_key_exists($k, $p)) {
                    $p[$k] = Money::toInt($p[$k]);
                }
            }
            return $p;
        }, $products);
    }

    public function remove(int $transactionId, string $byLabel, string $userEmail = ''): array
    {
        if ($transactionId <= 0) {
            throw new \InvalidArgumentException('Invalid transaction_id');
        }
        $settings = $this->settings->load();
        $api      = $this->poster->client();

        // 1. Date guard: fetch the check and refuse anything older than
        //    MAX_AGE_DAYS — before, ANY check id (months-old, closed and
        //    paid) could be deleted with one DELETE request.
        $check = self::firstRow($api->request('dash.getTransaction', [
            'transaction_id'   => $transactionId,
            'include_history'  => 0,
            'include_products' => 0,
        ]));
        if ($check === null) {
            throw new \DomainException('Чек ' . $transactionId . ' не найден в Poster.');
        }
        $closedTs = self::checkTs($check);
        if ($closedTs === null) {
            throw new \DomainException('Не удалось определить дату чека ' . $transactionId . ' — удаление отменено.');
        }
        if (!self::isWithinMaxAge($closedTs, time())) {
            throw new \DomainException(sprintf(
                'Чек %d от %s: удалять можно только чеки за последние %d дн.',
                $transactionId, date('Y-m-d', $closedTs), self::MAX_AGE_DAYS,
            ));
        }

        // 2. Audit row BEFORE the destructive call (survives a crash mid-way;
        //    unlike the Telegram note its destination can't be re-pointed).
        $this->audit->record($userEmail !== '' ? $userEmail : $byLabel, 'poster_check.remove', [
            'transaction_id' => $transactionId,
            'closed_at'      => date('Y-m-d H:i:s', $closedTs),
            'sum'            => Money::posterMinorToVnd($check['sum'] ?? $check['payed_sum'] ?? 0),
            'by'             => $byLabel,
        ]);

        $resp = $api->request('transactions.removeTransaction', [
            'spot_tablet_id' => PosterIds::SPOT_TABLET_ID,
            'transaction_id' => $transactionId,
            'user_id'        => $settings->serviceUserId,
        ], 'POST');
        $errCode = is_array($resp) ? (int)($resp['err_code'] ?? 0) : 0;
        if ($errCode !== 0) {
            throw new \RuntimeException('Poster: err_code=' . $errCode);
        }

        $by   = trim($byLabel) !== '' ? trim($byLabel) : '—';
        $text = sprintf('Удален чек (%d) и кем - %s', $transactionId, $by);
        $tg   = $this->tg->sendText($text, $settings->telegramChatId, $settings->telegramThreadId);
        return [
            'ok'             => true,
            'telegram_ok'    => (bool)($tg['ok'] ?? false),
            'telegram_error' => $tg['error'] ?? '',
        ];
    }

    /** Calendar-day rule: closed today or within the previous MAX_AGE_DAYS days. */
    public static function isWithinMaxAge(int $closedTs, int $nowTs): bool
    {
        $oldestAllowed = date('Y-m-d', (int)strtotime('-' . self::MAX_AGE_DAYS . ' days', $nowTs));
        return date('Y-m-d', $closedTs) >= $oldestAllowed;
    }

    /** dash.getTransaction answers with the row itself or a one-item list. */
    private static function firstRow(mixed $resp): ?array
    {
        if (!is_array($resp) || $resp === []) return null;
        if (array_is_list($resp)) return is_array($resp[0] ?? null) ? $resp[0] : null;
        return $resp;
    }

    /** Close time of a check; an open check falls back to its start time. */
    private static function checkTs(array $check): ?int
    {
        foreach (['date_close', 'date_close_date', 'dateClose', 'date_start', 'dateStart'] as $k) {
            $v = $check[$k] ?? null;
            if ($v === null || $v === '' || $v === '0' || $v === 0) continue;
            $t = PosterTime::toUnix($v);
            if ($t !== null) return $t;
        }
        return null;
    }
}
