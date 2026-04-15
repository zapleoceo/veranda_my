import re

with open('src/classes/PosterReservationHelper.php', 'r') as f:
    content = f.read()

# Find where API is instantiated
pattern = r"(\$api = new PosterAPI\(\$apiToken\);)"

def repl(m):
    return m.group(1) + """
            
            // Check for duplicates first
            $existingRes = $api->request('incomingOrders.getReservations', [
                'timezone' => 'client',
            ], 'GET');
            
            if (is_array($existingRes)) {
                $siteMarker1 = '(Site #' . $reservationId . ')';
                $siteMarker2 = 'Сайт #' . $reservationId;
                $siteMarker3 = 'TG'; // Might be dangerous if just checking TG, but we check if it matches table and date
                
                $dt = \\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['start_time']);
                if (!$dt) { try { $dt = new \\DateTimeImmutable((string)$row['start_time']); } catch (\\Throwable $e) {} }
                $targetTs = $dt ? $dt->getTimestamp() : strtotime((string)$row['start_time']);
                
                foreach ($existingRes as $pr) {
                    $status = (int)($pr['status'] ?? 0);
                    if ($status === 7) continue; // Canceled
                    if ((int)($pr['spot_id'] ?? 0) !== (int)($spotId === '' || $spotId === '0' ? '1' : $spotId)) continue;
                    
                    $dr = trim((string)($pr['date_reservation'] ?? ''));
                    $prTs = strtotime($dr);
                    
                    $prComment = (string)($pr['comment'] ?? '');
                    
                    // Direct match by comment marker
                    $isSameMarker = (strpos($prComment, $siteMarker1) !== false || strpos($prComment, $siteMarker2) !== false);
                    
                    // Match by table, time and phone (if it's the exact same reservation created manually)
                    // We need table_id, but here we don't have $tableId resolved yet.
                    // Wait, let's resolve $tableId first.
                }
            }
"""

# Actually, it's better to put duplicate check AFTER resolving $tableId, so we can check by table and time if no comment marker.
