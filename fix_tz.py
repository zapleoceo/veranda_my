import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_tz_set = "date_default_timezone_set('Asia/Ho_Chi_Minh');"
new_tz_set = """$apiTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? 'Asia/Ho_Chi_Minh'));
if ($apiTzName === '') $apiTzName = 'Asia/Ho_Chi_Minh';
date_default_timezone_set($apiTzName);"""

if old_tz_set in content:
    content = content.replace(old_tz_set, new_tz_set)

# Find all 'finance.getTransactions' array arguments and insert 'timezone' => $apiTzName,
# Be careful with the syntax.
def replacer(m):
    body = m.group(2)
    if "'timezone'" not in body:
        # If it ends with a comma or spaces, append
        if not body.strip().endswith(','):
            body += ','
        body += "\n                'timezone' => $apiTzName,"
    return m.group(1) + body + m.group(3)

content = re.sub(
    r"('finance\.getTransactions',\s*\[)(.*?)(])",
    replacer,
    content,
    flags=re.DOTALL
)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done tz fix")
