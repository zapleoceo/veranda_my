<?php
$payload = [
    "table_num" => "10",
    "name" => "Test",
    "phone" => "+123456789",
    "comment" => "test",
    "guests" => 2,
    "start" => "2025-05-10T12:00",
    "tg" => [
        "user_id" => 12345678,
        "username" => "testuser"
    ]
];

$ch = curl_init("http://localhost:8080/Tr2.php?ajax=submit_booking");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP CODE: $http_code\n";
echo "RESPONSE: $resp\n";
