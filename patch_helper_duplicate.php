<?php
$php = file_get_contents('src/classes/PosterReservationHelper.php');

$old = <<<PHP
            \$reservationData = [
                'spot_id'          => \$spotId,
                'phone'            => \$phone,
                'table_id'         => (string)\$tableId,
                'guests_count'     => (string)\$row['guests'],
                'date_reservation' => \$dateReservation,
                'duration'         => '7200', // 2 hours default in seconds
                'first_name'       => \$firstName,
                'last_name'        => \$lastName,
                'comment'          => trim((\$row['comment'] ?? '') . ' ' . (\$commentSuffix ?: '(Site #' . \$row['id'] . ')'))
            ];

            \$resp = \$api->request('incomingOrders.createReservation', \$reservationData, 'POST');
PHP;

$new = <<<PHP
            \$reservationData = [
                'spot_id'          => \$spotId,
                'phone'            => \$phone,
                'table_id'         => (string)\$tableId,
                'guests_count'     => (string)\$row['guests'],
                'date_reservation' => \$dateReservation,
                'duration'         => '7200', // 2 hours default in seconds
                'first_name'       => \$firstName,
                'last_name'        => \$lastName,
                'comment'          => trim((\$row['comment'] ?? '') . ' ' . (\$commentSuffix ?: '(Site #' . \$row['id'] . ')'))
            ];

            // 1. Check for duplicates
            \$existingRes = \$api->request('incomingOrders.getReservations', [
                'timezone' => 'client',
            ], 'GET');

            \$foundDuplicateId = 0;
            if (is_array(\$existingRes)) {
                \$targetTs = strtotime(\$dateReservation);
                \$siteMarker1 = '(Site #' . \$reservationId . ')';
                \$siteMarker2 = 'Сайт #' . \$reservationId;

                foreach (\$existingRes as \$pr) {
                    \$status = (int)(\$pr['status'] ?? 0);
                    if (\$status === 7) continue; // Canceled
                    if ((int)(\$pr['spot_id'] ?? 0) !== (int)\$spotId) continue;
                    
                    \$prTs = strtotime((string)(\$pr['date_reservation'] ?? ''));
                    \$prTableId = (int)(\$pr['table_id'] ?? 0);
                    \$prComment = (string)(\$pr['comment'] ?? '');
                    
                    \$isSameMarker = (strpos(\$prComment, \$siteMarker1) !== false || strpos(\$prComment, \$siteMarker2) !== false);
                    
                    // Allow small time difference (e.g., 30 mins) if it's the exact same table and phone
                    \$isSameTableAndTime = (\$prTableId === \$tableId && abs(\$prTs - \$targetTs) <= 1800);
                    \$prPhone = preg_replace('/\D+/', '', (string)(\$pr['phone'] ?? ''));
                    \$myPhone = preg_replace('/\D+/', '', \$phone);
                    \$isSamePhone = (\$prPhone !== '' && \$myPhone !== '' && (strpos(\$prPhone, \$myPhone) !== false || strpos(\$myPhone, \$prPhone) !== false));
                    
                    if (\$isSameMarker || (\$isSameTableAndTime && \$isSamePhone)) {
                        \$foundDuplicateId = (int)(\$pr['incoming_order_id'] ?? 0);
                        break;
                    }
                }
            }

            if (\$foundDuplicateId > 0) {
                // Already exists, just mark as pushed
                \$resp = ['incoming_order_id' => \$foundDuplicateId];
                \$db->query("UPDATE {\$resTable} SET is_poster_pushed = 1, poster_id = ? WHERE id = ?", [\$foundDuplicateId, \$reservationId]);
                return ['ok' => true, 'poster_res' => \$resp, 'duplicate' => true];
            }

            \$resp = \$api->request('incomingOrders.createReservation', \$reservationData, 'POST');
PHP;

$php = str_replace($old, $new, $php);
file_put_contents('src/classes/PosterReservationHelper.php', $php);
