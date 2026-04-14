import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_match = """        $isMatchKind = function (array $r, string $kind): bool {
            $type = (int)($r['type'] ?? 0);
            if ($type !== 1) return false;
            $accId = (int)($r['account_id'] ?? 0);
            $amount = (int)($r['amount'] ?? 0);
            if ($amount <= 0) return false;
            $cmt = (string)($r['comment'] ?? $r['description'] ?? $r['comment_text'] ?? '');
            $cmt = mb_strtolower(trim($cmt), 'UTF-8');
            if ($kind === 'vietnam') {
                return $accId === 9 && mb_stripos($cmt, 'вьетна', 0, 'UTF-8') !== false;
            }
            if ($kind === 'tips') {
                return $accId === 8 && (mb_stripos($cmt, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmt, 'tips', 0, 'UTF-8') !== false);
            }
            return false;
        };"""

new_match = """        $isMatchKind = function (array $r, string $kind): bool {
            if (((int)($r['status'] ?? 0)) === 3) return false;
            $tRaw = (string)($r['type'] ?? '');
            $type = (int)$tRaw;
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            
            if ($isTransfer || $isIn || $isOut) {
                if ($isTransfer || $isIn) {
                    $accId = (int)($r['account_id'] ?? 0);
                } else {
                    $accId = (int)($r['recipient_id'] ?? $r['account_to'] ?? $r['account_to_id'] ?? 0);
                }
                $expectedTo = ($kind === 'vietnam') ? 9 : (($kind === 'tips') ? 8 : 0);
                if ($accId === $expectedTo) {
                    $cmt = (string)($r['comment'] ?? $r['description'] ?? $r['comment_text'] ?? '');
                    $cmt = mb_strtolower(trim($cmt), 'UTF-8');
                    if ($kind === 'vietnam' && mb_stripos($cmt, 'вьетна', 0, 'UTF-8') !== false) return true;
                    if ($kind === 'tips' && (mb_stripos($cmt, 'типс', 0, 'UTF-8') !== false || mb_stripos($cmt, 'tips', 0, 'UTF-8') !== false)) return true;
                }
            }
            return false;
        };"""

content = content.replace(old_match, new_match)

# And if they actually want to delete the transaction from Poster, we should add an API call.
# The user asked: "Скорее всего нет запроса апи на удаление транзакции,  какой запрос ты используешь ?"
# Let's add finance.removeTransaction if it has a txId.

old_insert = """        $db->query(
            "INSERT INTO {$pfh} (date_to, kind, transfer_id, tx_id, comment, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$dTo, $kind, $transferId > 0 ? $transferId : null, $txId > 0 ? $txId : null, $comment !== '' ? $comment : null, $by !== '' ? $by : null]
        );"""

new_insert = """        // Сначала удаляем транзакцию из Poster API
        if ($txId > 0) {
            try {
                $api->request('finance.removeTransaction', [
                    'transaction_id' => $txId
                ], 'POST');
            } catch (\Throwable $e) {
                // Игнорируем ошибку удаления из API (возможно она уже удалена)
            }
        } elseif ($transferId > 0) {
            try {
                $api->request('finance.removeTransaction', [
                    'transaction_id' => $transferId
                ], 'POST');
            } catch (\Throwable $e) {
            }
        }

        $db->query(
            "INSERT INTO {$pfh} (date_to, kind, transfer_id, tx_id, comment, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$dTo, $kind, $transferId > 0 ? $transferId : null, $txId > 0 ? $txId : null, $comment !== '' ? $comment : null, $by !== '' ? $by : null]
        );"""

content = content.replace(old_insert, new_insert)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done match fix")
