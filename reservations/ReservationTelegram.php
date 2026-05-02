<?php

namespace App\Classes;

class ReservationTelegram {
    public static function buildManagerText(array $r): string {
        $qrCode = (string)($r['qr_code'] ?? '');
        $startRaw = (string)($r['start_time'] ?? '');
        $startDt = null;
        try { $startDt = new \DateTimeImmutable($startRaw); } catch (\Throwable $e) {}
        if (!$startDt) $startDt = new \DateTimeImmutable('now');

        $duration = (int)($r['duration'] ?? 0);
        if ($duration <= 0) $duration = 0;

        $guests = (int)($r['guests'] ?? 0);
        $tableNum = (string)($r['table_num'] ?? '');
        $name = (string)($r['name'] ?? '');
        $phone = (string)($r['phone'] ?? '');
        $waPhone = (string)($r['whatsapp_phone'] ?? '');
        $comment = trim((string)($r['comment'] ?? ''));
        $preorder = trim((string)($r['preorder_text'] ?? ''));
        $preorderRu = trim((string)($r['preorder_ru'] ?? ''));
        $tgUid = (int)($r['tg_user_id'] ?? 0);
        $tgUn = strtolower(trim((string)($r['tg_username'] ?? '')));
        $tgUn = ltrim($tgUn, '@');

        $text = '<b>Новая бронь с сайта #' . htmlspecialchars($qrCode) . '</b>' . "\n";
        $text .= 'Дата: <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
        $text .= 'Время: <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
        if ($duration > 0) {
            $hours = (int)floor($duration / 60);
            $mins = $duration % 60;
            $durStr = (string)$hours . ' ч' . ($mins > 0 ? (' ' . (string)$mins . ' м') : '');
            $text .= 'Продолжительность: <b>' . htmlspecialchars($durStr) . '</b>' . "\n";
        }
        $text .= 'Кол-во человек: <b>' . htmlspecialchars((string)$guests) . '</b>' . "\n";
        $text .= 'Номер стола: <b>' . htmlspecialchars($tableNum) . '</b>' . "\n";
        $text .= 'Имя: <b>' . htmlspecialchars($name) . '</b>' . "\n";
        $text .= 'Номер телефона: <b>' . htmlspecialchars($phone) . '</b>';

        if ($waPhone !== '') {
            $waClean = preg_replace('/\D+/', '', $waPhone);
            $text .= "\n" . 'WhatsApp: <a href="https://wa.me/' . htmlspecialchars($waClean) . '">+' . htmlspecialchars($waClean) . '</a>';
        }

        if ($comment !== '') {
            $text .= "\n";
            $text .= '<b>Комментарий:</b>' . "\n" . htmlspecialchars($comment);
        }

        $preForGroup = $preorderRu !== '' ? $preorderRu : $preorder;
        if ($preForGroup !== '') {
            $text .= "\n";
            $text .= '<b>Предзаказ:</b>' . "\n" . htmlspecialchars($preForGroup);
        }

        if ($waPhone === '' && ($tgUn !== '' || $tgUid > 0)) {
            $text .= "\n";
            $text .= 'Telegram: ';
            if ($tgUn !== '') {
                $text .= '<a href="https://t.me/' . htmlspecialchars($tgUn) . '">@' . htmlspecialchars($tgUn) . '</a>';
                if ($tgUid > 0) $text .= ' · <a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a>';
            } elseif ($tgUid > 0) {
                $text .= '<a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a> (id ' . htmlspecialchars((string)$tgUid) . ')';
            }
        }

        $text .= "\n\n@Ollushka90 @ce_akh1  свяжитесь с гостем";
        return $text;
    }

    public static function keyboardActive(int $id): array {
        return [
            [
                [
                    'text' => 'вPoster',
                    'callback_data' => 'vposter:' . $id
                ],
                [
                    'text' => 'отказать',
                    'callback_data' => 'vdecline:' . $id
                ],
            ],
        ];
    }

    public static function keyboardDeclined(int $id): array {
        return [
            [
                [
                    'text' => 'восстановить',
                    'callback_data' => 'vrestore:' . $id
                ],
            ],
        ];
    }

    public static function keyboardStale(int $id): array {
        return [
            [
                [
                    'text' => 'Обновить время и отправить в Poster',
                    'callback_data' => 'vposter_fix:' . $id
                ],
            ],
            [
                [
                    'text' => 'Отмена',
                    'callback_data' => 'vposter_cancel:' . $id
                ],
            ],
        ];
    }
}

