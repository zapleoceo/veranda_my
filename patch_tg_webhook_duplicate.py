import re

with open('telegram_webhook.php', 'r') as f:
    content = f.read()

pattern = r"(\$postJson\('answerCallbackQuery', \['callback_query_id' => \$callbackId, 'text' => 'Бронь создана в Poster!'\]\);\s*// Update message to show who sent it to Poster\s*\$oldText = \$message\['text'\] \?\? '';\s*\$newText = \$oldText \. \"\\n\\n🚀 <b>Отправлено в Poster</b> \(\" \. htmlspecialchars\(\$ackBy\) \. \"\)\";)"

def repl(m):
    return """
        if (!empty($res['duplicate'])) {
            $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже была в Poster!']);
            $newText = ($message['text'] ?? '') . "\\n\\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)";
        } else {
            $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь создана в Poster!']);
            $newText = ($message['text'] ?? '') . "\\n\\n🚀 <b>Отправлено в Poster</b> (" . htmlspecialchars($ackBy) . ")";
        }
"""

new_content = re.sub(pattern, repl, content, flags=re.DOTALL)
with open('telegram_webhook.php', 'w') as f:
    f.write(new_content)
