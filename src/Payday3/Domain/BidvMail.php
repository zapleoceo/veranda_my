<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Which BIDV SmartBanking mails are payments (rows of the OUT table).
 *
 * bidvsmartbanking@bidv.com.vn sends payment receipts AND service mail
 * to the same inbox. Survey of verandapayments@gmail.com on 18.09.2026 —
 * 987 BIDV mails, 9 distinct subjects:
 *
 *   receipts (976 mails, every one carries an amount):
 *     Interbank transfer receipt        Biên lai chuyển tiền ngoài BIDV
 *     Winthin BIDV transfer receipt     Biên lai chuyển tiền nội bộ BIDV
 *     Topup receipt                     Biên lai nạp tiền điện thoại
 *
 *   service mail (11 mails, none carries an amount):
 *     BIDV Verification Code - Mã OTP xác nhận đăng ký nhận thông báo …
 *     WARNING TO ACCESS BIDV SMARTBANKING ON ANOTHER DEVICE
 *     CẢNH BÁO TRUY CẬP TÀI KHOẢN BIDV SMARTBANKING TRÊN THIẾT BỊ KHÁC
 *
 * Receipts are recognised by keyword ("receipt" / "Biên lai"), not by an
 * exact subject list, so a new receipt type (bill payment, …) is picked
 * up on its own. "The body has a number with VND" is NOT a criterion:
 * limit warnings also quote a VND figure and would become phantom
 * expenses.
 */
final class BidvMail
{
    public static function isPaymentReceipt(string $subject): bool
    {
        $s = mb_strtolower(trim($subject), 'UTF-8');
        if ($s === '') return false;
        // bi.{1,2}n — «ê» arrives either as one code point (NFC) or as
        // «e» + combining circumflex (NFD); a literal match would silently
        // miss half of the mailbox.
        return (bool)preg_match('/receipt|bi.{1,2}n\s*lai/iu', $s);
    }
}
