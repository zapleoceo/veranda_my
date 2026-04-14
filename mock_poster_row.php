<?php
$row = [
    'transaction_id' => 12345,
    'date' => '2026-04-13 23:55:00',
    'type' => '2',
    'account_from' => 1,
    'account_to' => 9,
    'amount' => 1040000,
    'comment' => 'Перевод чеков вьетнаской компании by verandamy26@gmail.com'
];

$tRaw = (string)($row['type'] ?? '');
$isTransfer = ($tRaw === '2');

$dRaw = $row['date'] ?? null;
$ts = null;
if (is_numeric($dRaw)) {
    $ts = (int)$dRaw;
} elseif (is_string($dRaw) && trim($dRaw) !== '') {
    $ts = strtotime($dRaw);
}

$accFromId = 0;
$accToId = 0;
if ($isTransfer) {
    $fromRaw = $row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0;
    if (is_array($fromRaw)) $fromRaw = $fromRaw['account_id'] ?? $fromRaw['id'] ?? 0;
    $accFromId = (int)$fromRaw;

    $toRaw = $row['account_to'] ?? $row['account_to_id'] ?? $row['recipient_id'] ?? 0;
    if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
    $accToId = (int)$toRaw;
}

var_dump($accFromId, $accToId);

if ($accToId !== 8 && $accToId !== 9 && $accFromId !== 8 && $accFromId !== 9) {
    echo "Skipped by accToId/accFromId\n";
} else {
    $accId = ($accToId === 8 || $accToId === 9) ? $accToId : $accFromId;
    echo "accId: $accId\n";
    
    $cmt = (string)($row['comment'] ?? '');
    $cmtNorm = mb_strtolower(trim($cmt), 'UTF-8');
    $isVietnam = $accId === 9 && mb_stripos($cmtNorm, 'вьетна', 0, 'UTF-8') !== false;
    
    var_dump($isVietnam);
}
