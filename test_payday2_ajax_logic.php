<?php
$rows = [
    [
        'transaction_id' => 123,
        'type' => 2,
        'date' => '2026-04-13 23:55:00',
        'account_from' => 1,
        'account_to' => 9,
        'amount' => 104000000,
        'comment' => 'Перевод чеков вьетнаской компании by verandamy26@gmail.com'
    ]
];

$startTs = strtotime('2026-04-13 00:00:00');
$endTs = strtotime('2026-04-13 23:59:59');
$kind = 'vietnam';

$normMoney = function ($sumRaw): int {
    $sumF = 0.0;
    if (is_int($sumRaw) || is_float($sumRaw)) $sumF = (float)$sumRaw;
    else if (is_string($sumRaw)) $sumF = (float)str_replace(',', '.', str_replace(' ', '', trim($sumRaw)));
    $sumInt = (int)round($sumF);
    return ($sumInt > 200000000 && $sumInt % 100 === 0) ? (int)round($sumInt / 100) : $sumInt;
};
$normText = function (string $s): string {
    $t = trim($s);
    return mb_strtolower($t, 'UTF-8');
};
$posterCentsToVnd = function (int $cents): int {
    if ($cents === 0) return 0;
    if ($cents % 100 === 0) return (int)($cents / 100);
    return (int)round($cents / 100);
};

$out = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $tRaw = (string)($row['type'] ?? '');
    $isTransfer = ($tRaw === '2');
    $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
    $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
    if (!$isTransfer && !$isIn && !$isOut) continue;

    $dRaw = $row['date'] ?? $row['created_at'] ?? $row['createdAt'] ?? $row['time'] ?? $row['datetime'] ?? $row['date_time'] ?? $row['created'] ?? null;
    $ts = null;
    if (is_numeric($dRaw)) {
        $n = (int)$dRaw;
        if ($n > 2000000000000) $n = (int)round($n / 1000);
        if ($n > 0) $ts = $n;
    } elseif (is_string($dRaw) && trim($dRaw) !== '') {
        $t = strtotime($dRaw);
        if ($t !== false && $t > 0) $ts = $t;
    }
    if ($ts === null) { echo "ts null\n"; continue; }
    if ($ts < $startTs || $ts > $endTs) { echo "ts out of range\n"; continue; }

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

    if ($accToId !== 8 && $accToId !== 9 && $accFromId !== 8 && $accFromId !== 9) { echo "acc filter fail\n"; continue; }
    $accId = ($accToId === 8 || $accToId === 9) ? $accToId : $accFromId;

    $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
    $cmtNorm = $normText($cmt);
    $isVietnam = $accId === 9 && mb_stripos($cmtNorm, 'вьетна', 0, 'UTF-8') !== false;
    $isTips = $accId === 8 && (mb_stripos($cmtNorm, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmtNorm, 'tips', 0, 'UTF-8') !== false);
    if ($kind === 'vietnam' && !$isVietnam) { echo "vietnam filter fail\n"; continue; }
    if ($kind === 'tips' && !$isTips) { echo "tips filter fail\n"; continue; }
    
    echo "Found!\n";
}
