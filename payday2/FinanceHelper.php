<?php
namespace App\Payday2;

class FinanceHelper {
    public static function moneyToInt($v): int {
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)round($v);
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return 0;
            $t = str_replace(',', '.', $t);
            if (is_numeric($t)) return (int)round((float)$t);
            return 0;
        }
        if (is_numeric($v)) return (int)round((float)$v);
        return 0;
    }

    public static function posterCentsToVnd(int $cents): int {
        if ($cents === 0) return 0;
        if ($cents % 100 === 0) return (int)($cents / 100);
        return (int)round($cents / 100);
    }

    public static function fmtVndCents(int $cents): string {
        $neg = $cents < 0;
        $abs = $neg ? -$cents : $cents;
        $int = (int)round($abs / 100);
        $intFmt = number_format($int, 0, '.', "\u{202F}");
        return ($neg && $int > 0 ? '-' : '') . $intFmt;
    }

    public static function fmtVnd(int $val): string {
        $neg = $val < 0;
        $abs = $neg ? -$val : $val;
        $intFmt = number_format($abs, 0, '.', "\u{202F}");
        return ($neg && $abs > 0 ? '-' : '') . $intFmt;
    }

    public static function parsePosterDateTime($tx): ?string {
        $ts = null;
        if (is_array($tx)) {
            if (!empty($tx['date_close']) && is_numeric($tx['date_close'])) {
                $n = (int)$tx['date_close'];
                if ($n > 20000000000) $n = (int)round($n / 1000);
                if ($n > 0) $ts = $n;
            }
            if ($ts === null && !empty($tx['date_close']) && is_string($tx['date_close'])) {
                $t = strtotime($tx['date_close']);
                if ($t !== false && $t > 0) $ts = $t;
            }
            if ($ts === null && !empty($tx['date_close_date']) && is_string($tx['date_close_date'])) {
                $t = strtotime($tx['date_close_date']);
                if ($t !== false && $t > 0) $ts = $t;
            }
            if ($ts === null && !empty($tx['dateClose']) && is_string($tx['dateClose'])) {
                $t = strtotime($tx['dateClose']);
                if ($t !== false && $t > 0) $ts = $t;
            }
        }
        if ($ts === null) return null;
        if ((int)date('Y', $ts) < 2000) return null;
        return date('Y-m-d H:i:s', $ts);
    }
}
