import re

with open('reservations.php', 'r') as f:
    content = f.read()

pattern = r"(\$oldText = \"<b>Бронь с сайта #{\$id}<\/b>\\n\(Отправлена в Poster через сайт\)\";\s*// We don't have the original text easily accessible, so just remove the button\.\s*// Or maybe just removing the button is enough\.\s*\}\s*\})"

def repl(m):
    return """
                if (!empty($res['duplicate'])) {
                    $oldText = "<b>Бронь с сайта #{$id}</b>\\n(Уже была в Poster, дубль предотвращен)";
                } else {
                    $oldText = "<b>Бронь с сайта #{$id}</b>\\n(Отправлена в Poster через сайт)";
                }
                // We don't have the original text easily accessible, so just remove the button.
            }
        }
"""

new_content = re.sub(pattern, repl, content, flags=re.DOTALL)
with open('reservations.php', 'w') as f:
    f.write(new_content)
