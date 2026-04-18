<?php
namespace App\Classes;

require_once __DIR__ . '/PosterAPI.php';

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

        $locked = false;
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
                $locked = true;
            } catch (\Throwable $e) {
                try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $e2) {}
            }

            $api = new PosterAPI($apiToken);

            $spotIdInt = (int)$spotId;
            if ($spotIdInt <= 0) {
                $spotIdInt = 1;
            }

            $tableId = null;
            $rowNum = trim((string)($row['table_num'] ?? ''));

            // Helper to fetch tables for a specific hall
            $getTables = function (array $params) use ($api) {
                try {
                    return $api->request('spots.getTableHallTables', $params, 'GET');
                } catch (\Throwable $e) {
                    if (stripos((string)$e->getMessage(), 'http=405') !== false) {
                        return $api->request('spots.getTableHallTables', $params, 'POST');
                    }
                    throw $e;
                }
            };

            // Helper to fetch all halls
            $getHalls = function (int $sId) use ($api) {
                try {
                    return $api->request('spots.getSpotTablesHalls', ['spot_id' => $sId], 'GET');
                } catch (\Throwable $e) {
                    if (stripos((string)$e->getMessage(), 'http=405') !== false) {
                        return $api->request('spots.getSpotTablesHalls', ['spot_id' => $sId], 'POST');
                    }
                    throw $e;
                }
            };

            // 1. Try to get all halls and search in every hall
            $halls = [];
            try {
                $halls = $getHalls($spotIdInt);
            } catch (\Throwable $e) {}

            $allTablesFound = [];
            if (is_array($halls)) {
                foreach ($halls as $hall) {
                    $hallId = (int)($hall['hall_id'] ?? 0);
                    if ($hallId <= 0) continue;
                    
                    try {
                        $hallTables = $getTables(['spot_id' => $spotIdInt, 'hall_id' => $hallId, 'without_deleted' => 1]);
                        if (is_array($hallTables)) {
                            $allTablesFound = array_merge($allTablesFound, $hallTables);
                        }
                    } catch (\Throwable $e) {}
                }
            }

            // 2. If halls fetching failed or returned empty, try fallback without hall_id
            if (empty($allTablesFound)) {
                try {
                    $fallback = $getTables(['spot_id' => $spotIdInt, 'without_deleted' => 1]);
                    if (is_array($fallback)) {
                        $allTablesFound = $fallback;
                    }
                } catch (\Throwable $e) {}
            }

            // 3. Search in gathered tables
            foreach ($allTablesFound as $t) {
                $tTitle = trim((string)($t['table_title'] ?? ''));
                $tNum = trim((string)($t['table_num'] ?? ''));
                
                // User explicitly requested to match by table_title
                if (strcasecmp($tTitle, $rowNum) === 0 || $tTitle === $rowNum) {
                    $tableId = (int)$t['table_id'];
                    break;
                }
            }

            // Fallback: match by table_num if title didn't match
            if (!$tableId) {
                foreach ($allTablesFound as $t) {
                    $tNum = trim((string)($t['table_num'] ?? ''));
                    if (strcasecmp($tNum, $rowNum) === 0 || $tNum === $rowNum) {
                        $tableId = (int)$t['table_id'];
                        break;
                    }
                }
            }

            if (!$tableId) {
                // Return detailed error with the list of available table titles for debugging
                $availableTitles = [];
                foreach ($allTablesFound as $t) {
                    $availableTitles[] = '"' . ($t['table_title'] ?? '') . '" (id:' . ($t['table_id'] ?? '') . ')';
                }
                $titlesStr = empty($availableTitles) ? 'столы не найдены' : implode(', ', array_slice($availableTitles, 0, 5));
                return ['ok' => false, 'error' => 'Не удалось найти ID стола в Poster для номера ' . $rowNum . '. Доступные столы: ' . $titlesStr];
            }

            $fullName = trim((string)$row['name']);
            $nameParts = explode(' ', $fullName, 2);
            $firstName = trim($nameParts[0] ?? 'Guest');
            $lastName = trim($nameParts[1] ?? '');

            if ($spotId === '0' || $spotId === '') $spotId = '1';

            $rawPhone = (string)($row['phone'] ?? '');
            $digits = preg_replace('/\D+/', '', $rawPhone);
            if ($digits === '') {
                return ['ok' => false, 'error' => 'Телефон не указан'];
            }
            if (strpos($digits, '0') === 0 && strlen($digits) >= 10 && strlen($digits) <= 11) {
                $digits = '84' . substr($digits, 1);
            } elseif (strlen($digits) === 9 && preg_match('/^[35789]/', $digits)) {
                $digits = '84' . $digits;
            }
            $phone = '+' . $digits;

            $spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
            if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
                $spotTzName = 'Asia/Ho_Chi_Minh';
            }
            $spotTz = new \DateTimeZone($spotTzName);

            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['start_time'], $spotTz);
            if (!$dt) { try { $dt = new \DateTimeImmutable((string)$row['start_time'], $spotTz); } catch (\Throwable $e) { $dt = null; } }
            if (!$dt) {
                return ['ok' => false, 'error' => 'Некорректное время брони'];
            }
            $dateReservation = $dt->format('Y-m-d H:i:s');

            $rawCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['qr_code'] ?? '')));
            if ($rawCode === '') $rawCode = (string)$reservationId;
            $marker = '[VERANDA:' . $rawCode . ']';
            $actorStr = trim((string)$actor);
            $metaLine = $marker . ($actorStr !== '' ? (' by ' . $actorStr) : '');
            $commentBase = trim((string)($row['comment'] ?? ''));
            $commentFinal = $commentBase !== '' ? ($commentBase . "\n" . $metaLine) : $metaLine;

            $duration = (int)($row['duration'] ?? 120);
            if ($duration <= 0) $duration = 120;
            // Poster Incoming Orders API expects duration in SECONDS for reservations
            // The error message "greater than 1800" confirms it expects seconds (1800s = 30m)
            $durationSeconds = $duration * 60; 

            $reservationData = [
                'spot_id'          => (string)$spotIdInt,
                'type'             => '3',
                'phone'            => $phone,
                'table_id'         => (string)$tableId,
                'guests_count'     => (string)$row['guests'],
                'date_reservation' => $dateReservation,
                'duration'         => (string)$durationSeconds,
                'first_name'       => $firstName,
                'last_name'        => $lastName,
                'comment'          => $commentFinal,
            ];

            $dateFrom = date('Y-m-d 00:00:00');
            $dateTo = date('Y-m-d 23:59:59', strtotime('+60 days'));
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
                $locked = false;
                return ['ok' => true, 'poster_res' => ['incoming_order_id' => $foundDuplicateId, 'verified' => $verified], 'duplicate' => true];
            }

            try {
                // Try incomingOrders.createIncomingOrder instead of createReservation
                // as error 42 "incoming_order_id is undefined" often means createReservation 
                // is behaving like an update method or is deprecated in some Poster versions.
                $resp = $api->request('incomingOrders.createIncomingOrder', $reservationData, 'POST');
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // If Poster error 37 (invalid phone), retry with fallback phone
                if (strpos($msg, '37') !== false || stripos($msg, 'phone number') !== false) {
                    $reservationData['phone'] = '+94742688058';
                    $resp = $api->request('incomingOrders.createIncomingOrder', $reservationData, 'POST');
                } else {
                    throw $e;
                }
            }

            if (isset($resp['error']) || !isset($resp['incoming_order_id'])) {
                $err = $resp['error'] ?? 'Unknown Poster API Error';
                $db->query("UPDATE {$resTable} SET is_poster_pushed = 0 WHERE id = ?", [$reservationId]);
                return ['ok' => false, 'error' => "Poster API: " . json_encode($resp, JSON_UNESCAPED_UNICODE)];
            }

            $posterId = (int)($resp['incoming_order_id'] ?? 0);
            $db->query("UPDATE {$resTable} SET is_poster_pushed = 1, poster_id = ? WHERE id = ?", [$posterId, $reservationId]);
            $locked = false;

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
            return ['ok' => false, 'error' => 'Poster Error: ' . $msg];
        } finally {
            if ($locked) {
                try { $db->query("UPDATE {$resTable} SET is_poster_pushed = 0 WHERE id = ? LIMIT 1", [$reservationId]); } catch (\Throwable $e3) {}
            }
        }
    }
}
