import re

with open('assets/css/reservations.css', 'r') as f:
    content = f.read()

old = r"\.res-table, \.res-table tbody, \.res-table tr, \.res-table td \{ display: block; width: 100%; \}"
new = r".res-table, .res-table tbody, .res-table tr, .res-table td { display: block; width: 100%; box-sizing: border-box; }"

content = re.sub(old, new, content)

# Also let's ensure word break
old2 = r"\.res-table td \{ border-bottom: 1px solid rgba\(255,255,255,0\.08\); padding: 10px 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 6px; background: transparent !important; \}"
new2 = r".res-table td { border-bottom: 1px solid rgba(255,255,255,0.08); padding: 10px 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 6px; background: transparent !important; word-break: break-word; overflow-wrap: break-word; max-width: 100%; box-sizing: border-box; }"

content = re.sub(old2, new2, content)

with open('assets/css/reservations.css', 'w') as f:
    f.write(content)
