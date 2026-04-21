<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';
$token = '922371:489411264005b482039f38b8ee21f6fb';
$api = new \App\Classes\PosterAPI($token);

$s = $api->getSupply(1915);
$supplyData = [
    'supply_id'      => 1915,
    'storage_id'     => $s['storage_id'],
    'supplier_id'    => $s['supplier_id'],
    'date'           => $s['date'],
    'account_id'     => 2,
];

// Let's set it back to 6 total.
$payload = [
    'supply' => $supplyData,
    'ingredient' => [
        [
            'id' => 339,
            'type' => 4,
            'num' => 2,
            'sum' => 3 // unit price
        ]
    ]
];
$api->updateSupply($payload);

$s = $api->getSupply(1915);
echo "Restored to 6. Total Sum = " . $s['supply_sum'] . "\n";
echo "Ingredient Sum = " . $s['ingredients'][0]['supply_ingredient_sum'] . "\n";
