<?php

declare(strict_types=1);

namespace App\AiBot;

/** Explicit accounting forms for Poster income, expense, and transfer. */
final class DirectFinanceParser
{
    /** @return array{type:int,amount:int,account_hint:string,account_to_hint:string,comment:string}|null */
    public static function parse(string $text, string $botUsername, bool $allowUnaddressed = false): ?array
    {
        $body = trim($text);
        if ($botUsername !== '' && preg_match('/^@' . preg_quote($botUsername, '/') . '(?![\w])[\s,:;\-]*(.*)$/isu', $body, $m)) {
            $body = trim($m[1]);
        } elseif (!$allowUnaddressed) {
            return null;
        }

        $transfer = (bool) preg_match('/^(?:переведи|перевод)(?![\p{L}])\s*/iu', $body, $verb);
        if ($transfer) {
            $body = trim(substr($body, strlen($verb[0])));
        } else {
            $body = trim(preg_replace('/^(?:добавь|внеси|занеси|запиши|проведи)(?![\p{L}])\s*/iu', '', $body, 1) ?? $body);
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $body) ?: []), static fn(string $s) => $s !== ''));
        if ($lines === []) {
            return null;
        }
        $flat = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        if ($transfer) {
            if (count($lines) >= 4 && preg_match('/^(\d[\d .,]*)$/u', $lines[0], $amount)
                && preg_match('/^со\s+сч[её]та\s+(.+)$/iu', $lines[1], $from)
                && preg_match('/^на\s+сч[её]т\s+(.+)$/iu', $lines[2], $to)) {
                $account = trim($from[1]);
                $accountTo = trim($to[1]);
                $comment = trim(implode(' ', array_slice($lines, 3)));
                $rawAmount = trim($amount[1]);
            } elseif (preg_match('/^(\d[\d .,]*?)\s+со\s+сч[её]та\s+(\S+)\s+на\s+сч[её]т\s+(\S+)\s+(.+)$/iu', $flat, $m)) {
                [, $rawAmount, $account, $accountTo, $comment] = $m;
                $rawAmount = trim($rawAmount);
            } else {
                return null;
            }
            $type = 3;
        } else {
            if (count($lines) >= 3 && preg_match('/^([+-])\s*(\d[\d .,]*)$/u', $lines[0], $amount)
                && preg_match('/^(со\s+сч[её]та|на\s+сч[её]т)\s+(.+)$/iu', $lines[1], $accountLine)) {
                $sign = $amount[1];
                $rawAmount = trim($amount[2]);
                $account = trim($accountLine[2]);
                $comment = trim(implode(' ', array_slice($lines, 2)));
                $direction = mb_strtolower($accountLine[1], 'UTF-8');
            } elseif (preg_match('/^([+-])\s*(\d[\d .,]*?)\s+(со\s+сч[её]та|на\s+сч[её]т)\s+(\S+)\s+(.+)$/iu', $flat, $m)) {
                [, $sign, $rawAmount, $direction, $account, $comment] = $m;
                $rawAmount = trim($rawAmount);
                $direction = mb_strtolower($direction, 'UTF-8');
            } else {
                return null;
            }
            $type = $sign === '-' ? 2 : 1;
            if (($type === 2 && !str_starts_with($direction, 'со '))
                || ($type === 1 && !str_starts_with($direction, 'на '))) {
                return null;
            }
            $accountTo = '';
        }

        if (!preg_match('/^(?:\d+|\d{1,3}(?:[ .,]\d{3})+)$/', $rawAmount)) {
            return null;
        }
        $digits = preg_replace('/[ .,]/', '', $rawAmount) ?? '';
        $amountVnd = strlen($digits) <= 10 ? (int) $digits : 0;
        if ($amountVnd <= 0 || $amountVnd > PayoutParser::MAX_AMOUNT_VND
            || trim($account) === '' || ($type === 3 && trim($accountTo) === '') || trim($comment) === '') {
            return null;
        }
        return ['type' => $type, 'amount' => $amountVnd, 'account_hint' => trim($account),
            'account_to_hint' => trim($accountTo), 'comment' => trim($comment)];
    }
}
