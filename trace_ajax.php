<?php
$rows = [
    [
        'transaction_id' => 111,
        'type' => 0, // OUT from account 1
        'date' => '2026-04-13 23:55:00',
        'account_from' => 1,
        'account_to' => 0,
        'amount' => -1040000,
        'comment' => 'Перевод чеков вьетнаской компании by verandamy26@gmail.com'
    ],
    [
        'transaction_id' => 112,
        'type' => 1, // IN to account 9
        'date' => '2026-04-13 23:55:00',
        'account_from' => 0,
        'account_to' => 9,
        'amount' => 1040000,
        'comment' => 'Перевод чеков вьетнаской компании by verandamy26@gmail.com'
    ],
    [
        'transaction_id' => 113,
        'type' => 2, // TRANSFER from 1 to 9
        'date' => '2026-04-13 23:55:00',
        'account_from' => 1,
        'account_to' => 9,
        'amount' => 1040000,
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

    $dRaw = $row['date'];
    $ts = strtotime($dRaw);
    if ($ts < $startTs || $ts > $endTs) continue;

    $accFromId = 0;
    $accToId = 0;
    if ($isTransfer) {
        $accFromId = (int)$row['account_from'];
        $accToId = (int)$row['account_to'];
    } elseif ($isOut) {
        $accFromId = (int)$row['account_from'];
        $accToId = (int)$row['account_to'];
    } else {
        $accFromId = (int)$row['account_from'];
        $accToId = (int)$row['account_to'];
    }

    if ($accToId !== 8 && $accToId !== 9 && $accFromId !== 8 && $accFromId !== 9) {
        echo "Row {$row['transaction_id']} skipped: accToId=$accToId, accFromId=$accFromId\n";
        continue;
    }
    $accId = ($accToId === 8 || $accToId === 9) ? $accToId : $accFromId;

    $cmt = (string)($row['comment'] ?? '');
    $cmtNorm = $normText($cmt);
    $isVietnam = $accId === 9 && mb_stripos($cmtNorm, 'вьетна', 0, 'UTF-8') !== false;
    $isTips = $accId === 8 && (mb_stripos($cmtNorm, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmtNorm, 'tips', 0, 'UTF-8') !== false);
    
    if ($kind === 'vietnam' && !$isVietnam) {
        echo "Row {$row['transaction_id']} skipped: kind=vietnam, isVietnam=false\n";
        continue;
    }
    
    $sumRaw = $row['amount'] ?? 0;
    $sumMinor = abs($normMoney($sumRaw));
    $sum = (int)$posterCentsToVnd($sumMinor);

    echo "Row {$row['transaction_id']} INCLUDED: sum=$sum\n";
}
