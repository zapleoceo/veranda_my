<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\OnlineOrder\Contracts\PaymentQrProviderInterface;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;

/**
 * Bank-transfer QR via the keyless img.vietqr.io image API — the QR
 * encodes a NAPAS 247 transfer with the amount and reference pre-
 * filled; every Vietnamese banking app scans it natively. No API key,
 * no contract: works as soon as VIETQR_BANK_BIN + VIETQR_ACCOUNT_NO
 * are in .env.
 *
 * Payment confirmation is manual for now (operator checks the bank
 * app; the Telegram alert reminds them). The SePay webhook can later
 * automate it behind the same PaymentQrProviderInterface.
 */
final class VietQrPaymentProvider implements PaymentQrProviderInterface
{
    public function __construct(private readonly OnlineOrderConfig $config) {}

    public function qrFor(int $amountVnd, string $reference): ?array
    {
        if (!$this->config->isPaymentConfigured() || $amountVnd <= 0) {
            return null;
        }

        $bin     = rawurlencode($this->config->vietQrBankBin());
        $account = rawurlencode($this->config->vietQrAccountNo());
        $tpl     = rawurlencode($this->config->vietQrTemplate());

        $qrUrl = sprintf(
            'https://img.vietqr.io/image/%s-%s-%s.png?%s',
            $bin,
            $account,
            $tpl,
            http_build_query([
                'amount'      => $amountVnd,
                'addInfo'     => $reference,
                'accountName' => $this->config->vietQrAccountName(),
            ]),
        );

        return [
            'qr_url'       => $qrUrl,
            'account'      => $this->config->vietQrAccountNo(),
            'bank'         => $this->config->vietQrBankBin(),
            'account_name' => $this->config->vietQrAccountName(),
            'amount_vnd'   => $amountVnd,
            'reference'    => $reference,
        ];
    }
}
