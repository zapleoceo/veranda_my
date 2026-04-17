<?php
namespace App\Classes;

class PosterReservationHelper {
    public static function pushToPoster(Database $db, string $apiToken, int $reservationId, string $spotId = '1', string $actor = '') {
        if ($reservationId <= 0) {
            return ['ok' => false, 'error' => 'Invalid ID'];
        }

        $resTable = $db->t('reservations');
        $row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$reservationId])->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Reservation not found'];
        }

        $isPushed = (int)($row['is_poster_pushed'] ?? 0);
        if ($isPushed === 1) {
            return ['ok' => false, 'error' => 'Бронь уже отправлена в Poster'];
        }
        if ($isPushed === 2) {
            return ['ok' => false, 'error' => 'Бронь уже отправляется в Poster'];
        }

        if (empty($apiToken)) {
            return ['ok' => false, 'error' => 'Poster API not configured'];
        }

        try {
            $pdo = $db->getPdo();
            try {
                $pdo->beginTransaction();
                $lockRow = $db->query("SELECT is_poster_pushed FROM {$resTable} WHERE id = ? LIMIT 1 FOR UPDATE", [$reservationId])->fetch();
                $lockedState = is_array($lockRow) ? (int)($lockRow['is_poster_pushed'] ?? 0) : 0;
                if ($lockedState === 1) {
                    $pdo->commit();
                    return ['ok' => false, 'error' => 'Бронь уже отправлена в Poster'];
                }
                if ($lockedState === 2) {
                    $pdo->commit();
                    return ['ok' => false, 'error' => 'Бронь уже отправляется в Poster'];
                }
                $db->query("UPDATE {$resTable} SET is_poster_pushed = 2 WHERE id = ? LIMIT 1", [$reservationId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $e2) {}
            }

            $api = new PosterAPI($apiToken);

            $tableId = null;
            $allTables = $api->request('spots.getTableHallTables', ['spot_id' => 1, 'hall_id' => 2]);
            if (is_array($allTables)) {
                foreach ($allTables as $t) {
                    $tTitle = trim((string)($t['table_title'] ?? ''));
                    $rowNum = trim((string)($row['table_num'] ?? ''));
                    if ($tTitle === $rowNum) {
                        $tableId = (int)$t['table_id'];
                        break;
                    }
                }
            }

            if (!$tableId) {
                $allTablesFallback = $api->request('spots.getTables', ['spot_id' => 1]);
                if (is_array($allTablesFallback)) {
                    foreach ($allTablesFallback as $t) {
                        $tTitle = trim((string)($t['table_title'] ?? ''));
                        $rowNum = trim((string)($row['table_num'] ?? ''));
                        if ($tTitle === $rowNum) {
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

            // Clean up phone number but preserve + if present
            $phoneRaw = (string)$row['phone'];
            $phone = preg_replace('/[^\d\+]/', '', $phoneRaw);
            if (strpos($phone, '+') !== 0 && $phone !== '') {
                // If it doesn't start with +, but it's a valid length, add it.
                // Or if it's specifically Ukrainian 380:
                if (strpos($phone, '380') === 0) {
                    $phone = '+' . $phone;
                } else {
                    $phone = '+' . $phone; // Poster often requires + for international numbers
                }
            }

            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['start_time']);
            if (!$dt) { try { $dt = new \DateTimeImmutable((string)$row['start_time']); } catch (\Throwable $e) {} }
            $dateReservation = $dt ? $dt->format('Y-m-d H:i:s') : (string)$row['start_time'];

            $rawCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['qr_code'] ?? '')));
            if ($rawCode === '') $rawCode = (string)$reservationId;
            $marker = '[VERANDA:' . $rawCode . ']';
            $actorStr = trim((string)$actor);
            $metaLine = $marker . ($actorStr !== '' ? (' by ' . $actorStr) : '');
            $commentBase = trim((string)($row['comment'] ?? ''));
            $commentFinal = $commentBase !== '' ? ($commentBase . "\n" . $metaLine) : $metaLine;

            $reservationData = [
                'spot_id'          => $spotId,
                'phone'            => $phone,
                'table_id'         => (string)$tableId,
                'guests_count'     => (string)$row['guests'],
                'date_reservation' => $dateReservation,
                'duration'         => '7200', // 2 hours default in seconds
                'first_name'       => $firstName,
                'last_name'        => $lastName,
                'comment'          => $commentFinal
            ];

            $dateFrom = date('Y-m-d');
            $dateTo = date('Y-m-d', strtotime('+60 days'));
            $existingRes = $api->request('incomingOrders.getReservations', [
                'timezone' => 'client',
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ], 'GET');
            $foundDuplicateId = 0;
            $targetDtTs = $dt ? $dt->getTimestamp() : strtotime($dateReservation);

            if (is_array($existingRes)) {
                foreach ($existingRes as $pr) {
                    if (!is_array($pr)) continue;
                    $status = (int)($pr['status'] ?? 0);
                    if ($status === 7) continue;
                    if ((int)($pr['spot_id'] ?? 0) !== (int)$spotId) continue;
                    
                    // Check by marker in comment
                    $prComment = (string)($pr['comment'] ?? '');
                    if ($prComment !== '' && strpos($prComment, $marker) !== false) {
                        $foundDuplicateId = (int)($pr['incoming_order_id'] ?? 0);
                        break;
                    }

                    // Check by table, time (+/- 30 mins) and guest name
                    $prTableId = (int)($pr['table_id'] ?? 0);
                    if ($prTableId === $tableId) {
                        $prDateRaw = (string)($pr['date_reservation'] ?? '');
                        $prDtTs = strtotime($prDateRaw);
                        if ($prDtTs !== false && abs($prDtTs - $targetDtTs) <= 1800) { // 1800s = 30 mins
                            $prFirstName = trim((string)($pr['first_name'] ?? ''));
                            $prLastName = trim((string)($pr['last_name'] ?? ''));
                            if (strcasecmp($prFirstName, $firstName) === 0 || strcasecmp($prLastName, $firstName) === 0 || strcasecmp($prFirstName, $fullName) === 0) {
                                $foundDuplicateId = (int)($pr['incoming_order_id'] ?? 0);
                                break;
                            }
                        }
                    }
                }
            }

            if ($foundDuplicateId > 0) {
                $verified = null;
                try {
                    $verified = $api->request('incomingOrders.getReservation', ['incoming_order_id' => (string)$foundDuplicateId, 'timezone' => 'client'], 'GET');
                } catch (\Throwable $e) {
                    $verified = null;
                }
                $db->query("UPDATE {$resTable} SET is_poster_pushed = 1, poster_id = ? WHERE id = ?", [$foundDuplicateId, $reservationId]);
                return ['ok' => true, 'poster_res' => ['incoming_order_id' => $foundDuplicateId, 'verified' => $verified], 'duplicate' => true];
            }

            $resp = $api->request('incomingOrders.createReservation', $reservationData, 'POST');

            if (isset($resp['error']) || !isset($resp['incoming_order_id'])) {
                $err = $resp['error'] ?? 'Unknown Poster API Error';
                $db->query("UPDATE {$resTable} SET is_poster_pushed = 0 WHERE id = ?", [$reservationId]);
                return ['ok' => false, 'error' => "Poster API: " . json_encode($resp, JSON_UNESCAPED_UNICODE)];
            }

            $posterId = (int)($resp['incoming_order_id'] ?? 0);
            $db->query("UPDATE {$resTable} SET is_poster_pushed = 1, poster_id = ? WHERE id = ?", [$posterId, $reservationId]);

            return ['ok' => true, 'poster_res' => $resp];
        } catch (\Throwable $e) {
            try { $db->query("UPDATE {$resTable} SET is_poster_pushed = 0 WHERE id = ? LIMIT 1", [$reservationId]); } catch (\Throwable $e2) {}
            $msg = (string)$e->getMessage();
            if (stripos($msg, 'Не удалось найти ID стола') !== false) {
                return ['ok' => false, 'error' => $msg];
            }
            if (stripos($msg, 'Poster API Error') !== false) {
                return ['ok' => false, 'error' => 'Poster: ' . preg_replace('/^Poster API Error:\s*/i', '', $msg)];
            }
            if (stripos($msg, 'CURL Error') !== false) {
                return ['ok' => false, 'error' => 'Poster: ошибка сети/подключения'];
            }
            return ['ok' => false, 'error' => 'Poster: ошибка создания брони'];
        }
    }
}
