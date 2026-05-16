<?php

declare(strict_types=1);

namespace App\Services;

class ReservationMessagingService
{
    public function resend(array $row, string $target): array
    {
        $target    = in_array($target, ['both', 'guest', 'manager'], true) ? $target : 'both';
        $tgToken   = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
        $tgChatId  = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
        $tgThread  = (int)trim((string)($_ENV['TABLE_RESERVATION_THREAD_ID'] ?? ''));
        if ($tgToken === '' || $tgChatId === '') {
            return ['ok' => false, 'error' => 'Telegram not configured'];
        }

        $tz      = $this->_spotTz();
        $startDt = $this->_parseSpotDt((string)($row['start_time'] ?? ''), $tz)
            ?? new \DateTimeImmutable('now', $tz);

        $code    = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['qr_code'] ?? '')) ?: (string)$row['id']);
        $waDigits = preg_replace('/\D+/', '', (string)($row['whatsapp_phone'] ?? ''));
        $waPhoneNorm = ($waDigits !== '' && preg_match('/^[1-9]\d{8,14}$/', $waDigits)) ? ('+' . $waDigits) : '';
        $tgUid  = (int)($row['tg_user_id'] ?? 0);
        $guestChannel = $waPhoneNorm !== '' ? 'whatsapp' : ($tgUid > 0 ? 'telegram' : '');

        $bot     = new \App\Classes\TelegramBot($tgToken, $tgChatId);
        $okGroup = true;
        if ($target !== 'guest') {
            $okGroup = $bot->sendMessage(
                $this->_buildGroupText($row, $startDt, $code, $waPhoneNorm, $tgUid),
                $tgThread > 0 ? $tgThread : null
            );
        }

        $okGuest = null;
        if ($target !== 'manager' && $guestChannel !== '') {
            $okGuest = $this->_sendToGuest($row, $startDt, $code, $waPhoneNorm, $tgUid, $guestChannel, $tgToken);
        }

        return [
            'ok'           => true,
            'group_ok'     => $target === 'guest' ? null : $okGroup,
            'guest_ok'     => $target === 'manager' ? null : $okGuest,
            'has_tg'       => $tgUid > 0,
            'guest_channel' => $guestChannel,
        ];
    }

    private function _buildGroupText(array $row, \DateTimeImmutable $dt, string $code, string $waPhone, int $tgUid): string
    {
        $text  = '<b>[Повтор] Новая бронь с сайта #' . htmlspecialchars($code) . '</b>' . "\n";
        $text .= 'Дата: <b>' . $dt->format('Y-m-d') . '</b>' . "\n";
        $text .= 'Время: <b>' . $dt->format('H:i') . '</b>' . "\n";
        $text .= 'Кол-во человек: <b>' . htmlspecialchars((string)$row['guests']) . '</b>' . "\n";
        $text .= 'Номер стола: <b>' . htmlspecialchars((string)$row['table_num']) . '</b>' . "\n";
        $text .= 'Имя: <b>' . htmlspecialchars((string)$row['name']) . '</b>' . "\n";
        $text .= 'Номер телефона: <b>' . htmlspecialchars((string)$row['phone']) . '</b>';
        if ((string)($row['comment'] ?? '') !== '') {
            $text .= "\n<b>Комментарий:</b>\n" . htmlspecialchars((string)$row['comment']);
        }
        $pre = (string)($row['preorder_ru'] !== '' ? $row['preorder_ru'] : ($row['preorder_text'] ?? ''));
        if ($pre !== '') {
            $text .= "\n<b>Предзаказ:</b>\n" . htmlspecialchars($pre);
        }
        if ($waPhone !== '') {
            $clean = preg_replace('/\D+/', '', $waPhone);
            $text .= "\nWhatsApp: <a href=\"https://wa.me/{$clean}\">+{$clean}</a>";
        } elseif ($tgUid > 0) {
            $un = ltrim(trim((string)($row['tg_username'] ?? '')), '@');
            $text .= "\nTelegram: ";
            $text .= $un !== '' ? "<a href=\"https://t.me/{$un}\">@{$un}</a> · " : '';
            $text .= "<a href=\"tg://user?id={$tgUid}\">Открыть чат</a>";
        }
        if (!empty($row['zalo_phone'])) {
            $z = ltrim((string)$row['zalo_phone'], '+');
            $text .= "\nZalo: <a href=\"https://zalo.me/{$z}\">{$row['zalo_phone']}</a>";
        }
        $text .= "\n\n@Ollushka90 @ce_akh1 свяжитесь с гостем";
        return $text;
    }

    private function _sendToGuest(array $row, \DateTimeImmutable $dt, string $code, string $waPhone, int $tgUid, string $channel, string $tgToken): bool
    {
        $lang    = strtolower(trim((string)($row['lang'] ?? 'ru')));
        $lang    = in_array($lang, ['ru', 'en', 'vi'], true) ? $lang : 'ru';
        $tr      = $this->_translations($lang);
        $qrUrl   = (string)($row['qr_url'] ?? '');
        $html    = $this->_buildGuestHtml($row, $dt, $code, $tr, $qrUrl);
        $plain   = $this->_buildGuestPlain($row, $dt, $code, $tr, $qrUrl);

        if ($channel === 'whatsapp') {
            $waToken  = trim((string)($_ENV['WHATSAPP_TOKEN'] ?? ''));
            $waInstId = trim((string)($_ENV['WHATSAPP_INSTANCE_ID'] ?? ''));
            if ($waToken === '' || $waInstId === '') return false;
            try {
                $wa = new \App\Classes\WhatsAppAPI($waToken, $waInstId);
                return (bool)$wa->sendMessage($waPhone, $plain);
            } catch (\Throwable) { return false; }
        }

        // Telegram guest message
        if ($qrUrl !== '') {
            try {
                $imgData = @file_get_contents($qrUrl);
                if ($imgData !== false) {
                    $tmp = tempnam(sys_get_temp_dir(), 'qr_');
                    file_put_contents($tmp, $imgData);
                    $ch = curl_init("https://api.telegram.org/bot{$tgToken}/sendPhoto");
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                        CURLOPT_POSTFIELDS => [
                            'chat_id' => (string)$tgUid, 'parse_mode' => 'HTML',
                            'caption' => $html, 'photo' => new \CURLFile($tmp, 'image/png', 'qr.png'),
                        ],
                    ]);
                    $resp = curl_exec($ch); curl_close($ch); @unlink($tmp);
                    $data = $resp ? json_decode((string)$resp, true) : null;
                    if (is_array($data) && !empty($data['ok'])) return true;
                }
            } catch (\Throwable) {}
        }

        $ch = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => (string)$tgUid, 'text' => $html, 'parse_mode' => 'HTML']),
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        return is_array(json_decode((string)$resp, true)) && !empty(json_decode((string)$resp, true)['ok']);
    }

    private function _buildGuestHtml(array $row, \DateTimeImmutable $dt, string $code, array $tr, string $qrUrl): string
    {
        $t  = '<b>' . htmlspecialchars($tr['thanks_title']) . '</b> ' . htmlspecialchars($tr['thanks_body']) . "\n\n";
        if ($qrUrl !== '') {
            $t .= '<b>' . htmlspecialchars($tr['payment_title']) . "</b>\n";
            $t .= htmlspecialchars($tr['payment_body']) . "\n\n";
            $t .= '<a href="' . htmlspecialchars($qrUrl) . '">' . htmlspecialchars($tr['payment_link']) . '</a>' . "\n\n";
        }
        $t .= '<b>' . htmlspecialchars($tr['booking_title']) . ' #' . htmlspecialchars($code) . '</b>' . "\n";
        $t .= htmlspecialchars($tr['date']) . ': <b>' . $dt->format('Y-m-d') . '</b>' . "\n";
        $t .= htmlspecialchars($tr['time']) . ': <b>' . $dt->format('H:i') . '</b>' . "\n";
        $t .= htmlspecialchars($tr['guests']) . ': <b>' . htmlspecialchars((string)($row['guests'] ?? '')) . '</b>' . "\n";
        $t .= htmlspecialchars($tr['table']) . ': <b>' . htmlspecialchars((string)($row['table_num'] ?? '')) . '</b>' . "\n";
        $t .= htmlspecialchars($tr['name']) . ': <b>' . htmlspecialchars((string)($row['name'] ?? '')) . '</b>' . "\n";
        $t .= htmlspecialchars($tr['phone']) . ': <b>' . htmlspecialchars((string)($row['phone'] ?? '')) . '</b>';
        if ((string)($row['comment'] ?? '') !== '') {
            $t .= "\n<b>" . htmlspecialchars($tr['comment']) . ":</b>\n" . htmlspecialchars((string)$row['comment']);
        }
        if ((string)($row['preorder_text'] ?? '') !== '') {
            $t .= "\n<b>" . htmlspecialchars($tr['preorder']) . ":</b>\n" . htmlspecialchars((string)$row['preorder_text']);
        }
        return $t;
    }

    private function _buildGuestPlain(array $row, \DateTimeImmutable $dt, string $code, array $tr, string $qrUrl): string
    {
        $t  = $tr['thanks_title'] . ' ' . $tr['thanks_body'] . "\n\n";
        if ($qrUrl !== '') {
            $t .= $tr['payment_title'] . "\n" . $tr['payment_body'] . "\n\n" . $tr['payment_link'] . ': ' . $qrUrl . "\n\n";
        }
        $t .= $tr['booking_title'] . " #{$code}\n";
        $t .= $tr['date'] . ': ' . $dt->format('Y-m-d') . "\n";
        $t .= $tr['time'] . ': ' . $dt->format('H:i') . "\n";
        $t .= $tr['guests'] . ': ' . (string)($row['guests'] ?? '') . "\n";
        $t .= $tr['table'] . ': ' . (string)($row['table_num'] ?? '') . "\n";
        $t .= $tr['name'] . ': ' . (string)($row['name'] ?? '') . "\n";
        $t .= $tr['phone'] . ': ' . (string)($row['phone'] ?? '');
        if ((string)($row['comment'] ?? '') !== '') {
            $t .= "\n\n" . $tr['comment'] . ":\n" . (string)$row['comment'];
        }
        if ((string)($row['preorder_text'] ?? '') !== '') {
            $t .= "\n\n" . $tr['preorder'] . ":\n" . (string)$row['preorder_text'];
        }
        return $t;
    }

    private function _translations(string $lang): array
    {
        $all = [
            'ru' => [
                'thanks_title' => 'Спасибо!', 'thanks_body' => 'Мы с вами свяжемся в ближайшее время.',
                'payment_title' => 'Оплата предзаказа',
                'payment_body' => 'Пожалуйста, отсканируйте QR-код для оплаты предзаказа. В назначении платежа уже указан номер вашей брони.',
                'payment_link' => 'Ссылка на QR-код для оплаты',
                'booking_title' => 'Ваша бронь', 'date' => 'Дата', 'time' => 'Время',
                'guests' => 'Кол-во человек', 'table' => 'Номер стола', 'name' => 'Имя',
                'phone' => 'Номер телефона', 'comment' => 'Комментарий', 'preorder' => 'Предзаказ',
            ],
            'en' => [
                'thanks_title' => 'Thank you!', 'thanks_body' => 'We will contact you shortly.',
                'payment_title' => 'Pre-order payment',
                'payment_body' => 'Please scan the QR code to pay for the pre-order. The payment description already contains your reservation number.',
                'payment_link' => 'Payment QR link',
                'booking_title' => 'Your reservation', 'date' => 'Date', 'time' => 'Time',
                'guests' => 'Guests', 'table' => 'Table', 'name' => 'Name',
                'phone' => 'Phone', 'comment' => 'Comment', 'preorder' => 'Pre-order',
            ],
            'vi' => [
                'thanks_title' => 'Cảm ơn!', 'thanks_body' => 'Chúng tôi sẽ liên hệ với bạn sớm.',
                'payment_title' => 'Thanh toán đặt trước',
                'payment_body' => 'Vui lòng quét QR để thanh toán đặt trước. Nội dung chuyển khoản đã có mã đặt bàn của bạn.',
                'payment_link' => 'Link QR thanh toán',
                'booking_title' => 'Đặt bàn của bạn', 'date' => 'Ngày', 'time' => 'Giờ',
                'guests' => 'Số khách', 'table' => 'Bàn', 'name' => 'Tên',
                'phone' => 'Số điện thoại', 'comment' => 'Ghi chú', 'preorder' => 'Đặt trước',
            ],
        ];
        return $all[$lang] ?? $all['ru'];
    }

    private function _spotTz(): \DateTimeZone
    {
        $name = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
        return ($name !== '' && in_array($name, timezone_identifiers_list(), true))
            ? new \DateTimeZone($name)
            : new \DateTimeZone('Asia/Ho_Chi_Minh');
    }

    private function _parseSpotDt(string $s, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $v = trim($s);
        if ($v === '') return null;
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, $tz);
        if ($dt instanceof \DateTimeImmutable) return $dt;
        try { return new \DateTimeImmutable($v, $tz); } catch (\Throwable) { return null; }
    }
}
