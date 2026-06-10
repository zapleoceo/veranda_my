<?php

declare(strict_types=1);

namespace App\OnlineOrder\Contracts;

/**
 * Produces the bank-transfer QR the customer scans to prepay the food
 * (delivery fee is settled with the courier directly). Implementation:
 * VietQrPaymentProvider (keyless img.vietqr.io). A SePay-backed
 * provider with automatic payment confirmation can replace it later
 * behind this same interface.
 */
interface PaymentQrProviderInterface
{
    /**
     * @param int    $amountVnd food total in VND
     * @param string $reference transfer note the operator matches by
     * @return ?array{qr_url:string, account:string, bank:string, account_name:string, amount_vnd:int, reference:string}
     *         null when banking details are not configured yet.
     */
    public function qrFor(int $amountVnd, string $reference): ?array;
}
