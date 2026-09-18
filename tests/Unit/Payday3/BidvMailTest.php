<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\BidvMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Отсев не-платёжных писем BIDV из таблицы трат (вкладка OUT).
 *
 * Регрессия: в таблицу падали служебные письма банка — 17.09.2026 два
 * предупреждения о входе в SmartBanking с другого устройства, 18.09.2026
 * письмо с OTP-кодом. Суммы в них нет, парсер ставил 0 VND, и строки
 * навсегда висели красными (связать не с чем).
 *
 * Темы — ВСЕ 9 вариантов из реального ящика verandapayments@gmail.com
 * (обход 18.09.2026: 987 писем от bidvsmartbanking): 6 чеков, в каждом
 * из 976 писем есть сумма; 3 служебных, ни в одном из 11 суммы нет.
 */
final class BidvMailTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function receipts(): array
    {
        return [
            'interbank EN (287 писем)'   => ['Interbank transfer receipt'],
            'internal EN (290 писем)'    => ['Winthin BIDV transfer receipt'],
            'topup EN (3 письма)'        => ['Topup receipt'],
            'interbank VN (214 писем)'   => ['Biên lai chuyển tiền ngoài BIDV'],
            'internal VN (180 писем)'    => ['Biên lai chuyển tiền nội bộ BIDV'],
            'topup VN (2 письма)'        => ['Biên lai nạp tiền điện thoại'],
        ];
    }

    /** @return array<string,array{string}> */
    public static function serviceMail(): array
    {
        return [
            'OTP (5 писем, 18.09 — попало в таблицу)' => [
                'BIDV Verification Code - Mã OTP xác nhận đăng ký nhận thông báo kết quả giao dịch qua email',
            ],
            'вход с другого устройства EN (5 писем)' => ['WARNING TO ACCESS BIDV SMARTBANKING ON ANOTHER DEVICE'],
            'вход с другого устройства VN (1 письмо)' => ['CẢNH BÁO TRUY CẬP TÀI KHOẢN BIDV SMARTBANKING TRÊN THIẾT BỊ KHÁC'],
            'пустая тема'  => [''],
            'одни пробелы' => ['   '],
        ];
    }

    #[DataProvider('receipts')]
    public function test_payment_receipts_are_kept(string $subject): void
    {
        $this->assertTrue(BidvMail::isPaymentReceipt($subject));
    }

    #[DataProvider('serviceMail')]
    public function test_service_mail_is_dropped(string $subject): void
    {
        $this->assertFalse(BidvMail::isPaymentReceipt($subject));
    }

    public function test_vietnamese_subject_in_both_unicode_forms(): void
    {
        // NFC: «ê» одним кодовым знаком. NFD: «e» + комбинирующий циркумфлекс.
        // IMAP отдаёт темы в обеих формах — литеральное сравнение промахнулось бы.
        $this->assertTrue(BidvMail::isPaymentReceipt("Bi\u{EA}n lai chuy\u{1EC3}n ti\u{1EC1}n n\u{1ED9}i b\u{1ED9} BIDV"));
        $this->assertTrue(BidvMail::isPaymentReceipt("Bie\u{302}n lai chuy\u{1EC3}n ti\u{1EC1}n n\u{1ED9}i b\u{1ED9} BIDV"));
    }

    public function test_case_does_not_matter(): void
    {
        $this->assertTrue(BidvMail::isPaymentReceipt('INTERBANK TRANSFER RECEIPT'));
        $this->assertTrue(BidvMail::isPaymentReceipt('BIÊN LAI CHUYỂN TIỀN NỘI BỘ BIDV'));
    }

    public function test_a_new_receipt_type_is_picked_up_by_keyword(): void
    {
        // Новый тип чека (например, оплата счетов) не требует правки кода.
        $this->assertTrue(BidvMail::isPaymentReceipt('Bill payment receipt'));
        $this->assertTrue(BidvMail::isPaymentReceipt('Biên lai thanh toán hóa đơn'));
    }
}
