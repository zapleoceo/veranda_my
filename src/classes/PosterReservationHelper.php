<?php
namespace App\Classes;

class PosterReservationHelper {
    public static function pushToPoster(Database $db, string $apiToken, int $reservationId, string $spotId = '1', string $commentSuffix = '') {
        if ($reservationId <= 0) {
            return ['ok' => false, 'error' => 'Invalid ID'];
        }

        $resTable = $db->t('reservations');
        $row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$reservationId])->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Reservation not found'];
        }

        if (!empty($row['is_poster_pushed'])) {
            return ['ok' => false, 'error' => 'Бронь уже отправлена в Poster'];
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
                'comment'          => trim(($row['comment'] ?? '') . ' ' . ($commentSuffix ?: '(Site #' . $row['id'] . ')'))
            ];

            $resp = $api->request('incomingOrders.createReservation', $reservationData, 'POST');

            $posterId = (int)($resp['incoming_order_id'] ?? 0);
            $db->query("UPDATE {$resTable} SET is_poster_pushed = 1, poster_id = ? WHERE id = ?", [$posterId, $reservationId]);

            return ['ok' => true, 'poster_res' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Poster API error: ' . $e->getMessage()];
        }
    }
}
