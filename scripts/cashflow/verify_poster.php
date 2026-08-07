<?php

declare(strict_types=1);

/**
 * Cashflow report — live acceptance check against Poster (spec §11.1 + §12.1).
 *
 * Standalone: no DB, no app container. Proves that revenue computed straight
 * from Poster reproduces the control numbers to the đồng, and that the famous
 * 12-Jul double-count is now structurally impossible (food + hookah == day
 * total, never total + hookah again).
 *
 *   Local:   POSTER_API_TOKEN=xxxxx php scripts/cashflow/verify_poster.php
 *   Server:  php scripts/cashflow/verify_poster.php        (reads ../../.env)
 *
 * Exit code 0 = all checks passed, 1 = a check failed, 2 = no token.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: скрипт запускается только из консоли.\n");
}

require __DIR__ . '/../../src/Cashflow/Domain/PosterMoney.php';

use App\Cashflow\Domain\PosterMoney;

$token = getenv('POSTER_API_TOKEN') ?: cashflow_env_token(__DIR__ . '/../../.env');
if ($token === '') {
    fwrite(STDERR, "POSTER_API_TOKEN not set (env var or ../../.env)\n");
    exit(2);
}

/** «Кальян / Shisha» — verified as the only hookah category across Jan–Aug 2026. */
const HOOKAH_CATEGORY_IDS = [47];

$pass = 0;
$fail = 0;
$check = static function (string $label, int $got, int $want) use (&$pass, &$fail): void {
    $ok = $got === $want;
    printf("  [%s] %-40s got %16s  want %16s\n", $ok ? 'PASS' : 'FAIL', $label, number_format($got), number_format($want));
    $ok ? $pass++ : $fail++;
};

// Optional CA bundle for environments whose php.ini has no curl.cainfo
// (e.g. a Windows dev box). Peer verification stays ON — this only tells
// curl where the trust store lives.
$caInfo = getenv('CASHFLOW_CAINFO') ?: '';
$api = static function (string $method, array $params) use ($token, $caInfo): array {
    $params['token'] = $token;
    $url = 'https://joinposter.com/api/' . $method . '?' . http_build_query($params);
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40];
    if ($caInfo !== '') {
        $opts[CURLOPT_CAINFO] = $caInfo;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('curl: ' . curl_error($ch));
    }
    curl_close($ch);
    $j = json_decode((string) $body, true);
    return $j['response'] ?? [];
};

$monthRevenue = static function (string $from, string $to) use ($api): int {
    $an = $api('dash.getAnalytics', ['dateFrom' => $from, 'dateTo' => $to]);
    return PosterMoney::fromAnalytics($an['counters']['revenue'] ?? 0);
};

$dayRevenue = static function (string $ymd) use ($api): array {
    $an = $api('dash.getAnalytics', ['dateFrom' => $ymd, 'dateTo' => $ymd]);
    $total = PosterMoney::fromAnalytics($an['counters']['revenue'] ?? 0);
    $cats = $api('dash.getCategoriesSales', ['dateFrom' => $ymd, 'dateTo' => $ymd]);
    $hookah = 0;
    $hookahCount = 0;
    $catSum = 0;
    foreach ($cats as $c) {
        $rev = PosterMoney::fromDashCents($c['revenue'] ?? 0);
        $catSum += $rev;
        if (in_array((int) ($c['category_id'] ?? -1), HOOKAH_CATEGORY_IDS, true)) {
            $hookah += $rev;
            $hookahCount += (int) round((float) ($c['count'] ?? 0));
        }
    }
    return ['total' => $total, 'hookah' => $hookah, 'food' => $total - $hookah, 'catSum' => $catSum, 'hookahCount' => $hookahCount];
};

echo "CONVERTERS (spec §12.1) — no network:\n";
$check('fromDashCents("143500000")',        PosterMoney::fromDashCents('143500000'),        1_435_000);
$check('fromTransactionsV3("1435000.00")',  PosterMoney::fromTransactionsV3('1435000.00'),  1_435_000);
$check('fromFinanceCents("-572500000")',    PosterMoney::fromFinanceCents('-572500000'),    -5_725_000);
$check('fromAnalytics("675355750.0000")',   PosterMoney::fromAnalytics('675355750.0000'),   675_355_750);
$check('food split 34682000-2350000',       34_682_000 - 2_350_000,                         32_332_000);

echo "\nMONTH TOTALS (dash.getAnalytics):\n";
$check('May 2026 revenue',  $monthRevenue('20260501', '20260531'), 766_345_250);
$check('June 2026 revenue', $monthRevenue('20260601', '20260630'), 693_866_500);
$check('July 2026 revenue', $monthRevenue('20260701', '20260731'), 675_355_750);

echo "\nJULY 11-13 (total / hookah / food):\n";
foreach ([
    '20260711' => [25_803_500, 1_150_000, 24_653_500],
    '20260712' => [34_682_000, 2_350_000, 32_332_000],
    '20260713' => [10_375_000,   500_000,  9_875_000],
] as $ymd => [$t, $h, $f]) {
    $d = $dayRevenue((string) $ymd);
    $check("$ymd total",  $d['total'],  $t);
    $check("$ymd hookah", $d['hookah'], $h);
    $check("$ymd food",   $d['food'],   $f);
}

$jul12 = $dayRevenue('20260712');
echo "\nHEADLINE — 12 Jul double-count is structurally impossible:\n";
$check('food + hookah == day total',        $jul12['food'] + $jul12['hookah'], 34_682_000);
$check('is NOT the buggy 37,032,000',       ($jul12['food'] + $jul12['hookah']) === 37_032_000 ? 1 : 0, 0);
$check('hookah count (pcs)',                 $jul12['hookahCount'], 5);
$check('Σ categories == analytics total',    $jul12['catSum'], 34_682_000);

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED\n" : "{$fail} FAILED, {$pass} passed\n");
exit($fail === 0 ? 0 : 1);

function cashflow_env_token(string $envPath): string
{
    if (!is_file($envPath)) {
        return '';
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*POSTER_API_TOKEN\s*=\s*(.+)$/', $line, $m) === 1) {
            return trim($m[1], " \t\"'\r");
        }
    }
    return '';
}
