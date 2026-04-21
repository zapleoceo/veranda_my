import sys

with open('payday2/assets/js/payday2.js', 'r', encoding='utf-8') as f:
    lines = f.readlines()

out = []
in_settings = False
for i, line in enumerate(lines):
    if "const fillPayday2SettingsForm" in line:
        in_settings = True
    
    if "if (payday2InfoBtn)" in line and in_settings:
        in_settings = False
        
    if not in_settings:
        out.append(line)

with open('payday2/assets/js/payday2.js', 'w', encoding='utf-8') as f:
    f.writelines(out)

