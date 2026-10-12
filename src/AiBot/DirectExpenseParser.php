<?php

declare(strict_types=1);

namespace App\AiBot;

/** A deliberately narrow, unambiguous expense form addressed to this bot. */
final class DirectExpenseParser
{
    /** @return array{amount:int,account_hint:string,comment:string}|null */
    public static function parse(string $text, string $botUsername): ?array
    {
        if ($botUsername === '' || !preg_match('/^\s*@' . preg_quote($botUsername, '/') . '(?![\w])[\s,:;\-]*(.*)$/isu', $text, $addressed)) {
            return null;
        }
        $body = trim($addressed[1]);
        if (!preg_match('/^(?:добавь|внеси|занеси|запиши|проведи)(?![\p{L}])\s*-\s*(\d[\d .,]*)\s*\R+\s*со\s+сч[её]та\s+([^\r\n]+)\R+(.+)$/isu', $body, $m)) {
            // The amount may be on the same line as the verb, but the account and
            // comment occupy separate lines so neither can be guessed from prose.
            return null;
        }
        $rawAmount = trim($m[1]);
        if (!preg_match('/^(?:\d+|\d{1,3}(?:[ .,]\d{3})+)$/', $rawAmount)) {
            return null;
        }
        $digits = preg_replace('/[ .,]/', '', $rawAmount) ?? '';
        if (strlen($digits) > 10) {
            return null;
        }
        $amount = (int) $digits;
        $account = trim($m[2]);
        $comment = trim($m[3]);
        if ($amount <= 0 || $amount > PayoutParser::MAX_AMOUNT_VND || $account === '' || $comment === '') {
            return null;
        }
        return ['amount' => $amount, 'account_hint' => $account, 'comment' => $comment];
    }
}
