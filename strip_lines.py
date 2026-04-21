import sys

with open('payday2/assets/js/payday2.js', 'r', encoding='utf-8') as f:
    lines = f.readlines()

out = []
for i, line in enumerate(lines):
    # lines 2555 to 2985 are 0-indexed as 2554 to 2984
    if 2554 <= i <= 2984:
        continue
    out.append(line)

with open('payday2/assets/js/payday2.js', 'w', encoding='utf-8') as f:
    f.writelines(out)

