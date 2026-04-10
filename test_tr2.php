<?php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://veranda.my/Tr2.php?ajax=submit_booking");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'table_num' => '10',
    'guests' => 2,
    'start' => date('Y-m-d 14:00:00', strtotime('+1 day')),
    'duration_m' => 120,
    'name' => 'Test',
    'phone' => '+84 123 456 789',
    'comment' => 'Test',
    'preorder' => 'Test preorder',
    'preorder_ru' => 'Test preorder',
    'total_amount' => 100000,
    'lang' => 'ru',
    'tg' => ['user_id' => 3397075474, 'username' => 'testuser']
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP $httpcode\n";
echo $resp . "\n";
