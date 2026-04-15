<?php
namespace App\Classes;

class PosterReservationHelper {
    private static function normName(string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    public static function pushToPoster(Database $db, string $apiToken, int $reservationId, string $spotId = '1', string $commentSuffix = '', string $pushedBy = '') {
        if ($reservationId <= 0) {
            return ['ok' => false, 'error' => 'Invalid ID'];
        }

        $resTable = $db->t('reservations');
        $row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$reservationId])->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Reservation not found'];
        }

        $cols = [];
        try {
            foreach ($db->getPdo()->query("SHOW COLUMNS FROM {$resTable}") as $c) {
                $f = strtolower((string)($c['Field'] ?? ''));
                if ($f !== '') $cols[$f] = true;
            }
        } catch (\Throwable $e) {}

        $existingPosterId = (int)($row['poster_id'] ?? 0);
        if ($existingPosterId > 0) {
            return ['ok' => true, 'poster_res' => ['incoming_order_id' => $existingPosterId], 'duplicate' => true, 'already' => true];
        }

        if (empty($apiToken)) {
            return ['ok' => false, 'error' => 'Poster API not configured'];
        }

        try {
            $api = new PosterAPI($apiToken);

            $tableId = null;
            $allTables = $api->request('spots.getTableHallTables', ['spot_id' => 1, 'hall_id' => 2]);
            if (is_array($allTables)) {
                foreach ($allTables as $t) {
                    if (trim((string)($t['table_num'] ?? '')) === trim((string)($row['table_num'] ?? ''))) {
                        $tableId = (int)$t['table_id'];
                        break;
                    }
                }
            }

            if (!$tableId) {
                $allTablesFallback = $api->request('spots.getTables', ['spot_id' => 1]);
                if (is_array($allTablesFallback)) {
                    foreach ($allTablesFallback as $t) {
                        if (trim((string)($t['table_num'] ?? '')) === trim((string)($row['table_num'] ?? ''))) {
                            $tableId = (int)$t['table_id'];
                            break;
                        }
                    }
                }
            }

            if (!$tableId) {
                return ['ok' => false, 'error' => 'Не удалось найти ID стола в Poster для номера ' . $row['table_num']];
            }

            $fullName = trim((string)$row['name']);
            $nameParts = explode(' ', $fullName, 2);
            $firstName = trim($nameParts[0] ?? 'Guest');
            $lastName = trim($nameParts[1] ?? '');

            if ($spotId === '0' || $spotId === '') $spotId = '1';

            $phone = preg_replace('/\D+/', '', (string)$row['phone']);
            if (strpos($phone, '380') === 0) {
                $phone = '+' . $phone;
            }

            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['start_time']);
            if (!$dt) { try { $dt = new \DateTimeImmutable((string)$row['start_time']); } catch (\Throwable $e) {} }
            $dateReservation = $dt ? $dt->format('Y-m-d H:i:s') : (string)$row['start_time'];

            $reservationData = [
                'spot_id'          => $spotId,
                'phone'            => $phone,
                'table_id'         => (string)$tableId,
                'guests_count'     => (string)$row['guests'],
                'date_reservation' => $dateReservation,
                'duration'         => '7200', // 2 hours default in seconds
                'first_name'       => $firstName,
                'last_name'        => $lastName,
                'comment'          => trim(($row['comment'] ?? '') . ' ' . ($commentSuffix !== '' ? $commentSuffix . ' ' : '') . '(Site #' . $row['id'] . ')')
            ];

            $pushedAt = date('Y-m-d H:i:s');
            $pushedBy = trim((string)$pushedBy);
            if ($pushedBy === '') $pushedBy = 'system';

            // 1. Check for duplicates
            $existingRes = $api->request('incomingOrders.getReservations', [
                'timezone' => 'client',
            ], 'GET');

            $foundDuplicateId = 0;
            if (is_array($existingRes)) {
                $targetTs = strtotime($dateReservation);
                $targetName = self::normName((string)($row['name'] ?? ''));

                foreach ($existingRes as $pr) {
                    $status = (int)($pr['status'] ?? 0);
                    if ($status === 7) continue; // Canceled
                    if ((int)($pr['spot_id'] ?? 0) !== (int)$spotId) continue;
                    
                    $prTs = strtotime((string)($pr['date_reservation'] ?? ''));
                    if ($prTs <= 0 || $targetTs <= 0) continue;
                    if (abs($prTs - $targetTs) > 120) continue;

                    $prNameRaw = trim((string)($pr['client_name'] ?? ''));
                    if ($prNameRaw === '') {
                        $prNameRaw = trim((string)($pr['first_name'] ?? '') . ' ' . (string)($pr['last_name'] ?? ''));
                    }
                    if ($prNameRaw === '') {
                        $prNameRaw = trim((string)($pr['name'] ?? ''));
                    }
                    $prName = self::normName($prNameRaw);
                    if ($prName === '' || $targetName === '') continue;

                    if ($prName === $targetName) {
                        $foundDuplicateId = (int)($pr['incoming_order_id'] ?? $pr['id'] ?? 0);
                        break;
                    }
                }
            }

            if ($foundDuplicateId > 0) {
                $resp = ['incoming_order_id' => $foundDuplicateId];
                $set = [];
                $params = [];
                if (!empty($cols['poster_id'])) { $set[] = "poster_id = ?"; $params[] = $foundDuplicateId; }
                if (!empty($cols['poster_pushed_at'])) { $set[] = "poster_pushed_at = COALESCE(poster_pushed_at, ?)"; $params[] = $pushedAt; }
                if (!empty($cols['poster_pushed_by'])) { $set[] = "poster_pushed_by = COALESCE(poster_pushed_by, ?)"; $params[] = $pushedBy; }
                if (!empty($cols['poster_duplicate'])) { $set[] = "poster_duplicate = 1"; }
                if (!empty($set)) {
                    $params[] = $reservationId;
                    $db->query("UPDATE {$resTable} SET " . implode(', ', $set) . " WHERE id = ?", $params);
                }
                return ['ok' => true, 'poster_res' => $resp, 'duplicate' => true];
            }

            $resp = $api->request('incomingOrders.createReservation', $reservationData, 'POST');

            $posterId = (int)($resp['incoming_order_id'] ?? 0);
            if ($posterId > 0) {
                $set = [];
                $params = [];
                if (!empty($cols['poster_id'])) { $set[] = "poster_id = ?"; $params[] = $posterId; }
                if (!empty($cols['poster_pushed_at'])) { $set[] = "poster_pushed_at = COALESCE(poster_pushed_at, ?)"; $params[] = $pushedAt; }
                if (!empty($cols['poster_pushed_by'])) { $set[] = "poster_pushed_by = COALESCE(poster_pushed_by, ?)"; $params[] = $pushedBy; }
                if (!empty($cols['poster_duplicate'])) { $set[] = "poster_duplicate = 0"; }
                if (!empty($set)) {
                    $params[] = $reservationId;
                    $db->query("UPDATE {$resTable} SET " . implode(', ', $set) . " WHERE id = ?", $params);
                }
            }

            return ['ok' => true, 'poster_res' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
