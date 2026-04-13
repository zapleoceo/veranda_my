import re

with open('/workspace/payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove the original input.poster-cb binding
poster_cb_regex = re.compile(r"    document\.querySelectorAll\('input\.poster-cb'\)\.forEach\(\(cb\) => \{\n        cb\.addEventListener\('change', \(\) => \{\n            const id = Number\(cb\.getAttribute\('data-id'\) \|\| 0\);\n            if \(!id\) return;\n            if \(cb\.checked\) selectedPoster\.add\(id\);\n            else selectedPoster\.delete\(id\);\n            updateLinkButtonState\(\);\n        \}\);\n    \}\);\n", re.MULTILINE)
content = poster_cb_regex.sub('', content)

# 2. Wrap the IN table events in bindInTableEvents()
# Starts at "    document.querySelectorAll('input.sepay-cb')"
# Ends after the form.finance-transfer block
sepay_cb_start = "    document.querySelectorAll('input.sepay-cb')"
start_idx = content.find(sepay_cb_start)

# Find the end of the finance-transfer block.
# It ends right before "    const refreshLinks = () => {"
refresh_links_start = "    const refreshLinks = () => {"
end_idx = content.find(refresh_links_start)

if start_idx != -1 and end_idx != -1:
    block = content[start_idx:end_idx]
    
    # Indent the block by 4 spaces
    indented_block = "\n".join("    " + line if line.strip() else line for line in block.split('\n'))
    
    poster_cb_code = """    document.querySelectorAll('input.poster-cb').forEach((cb) => {
        cb.addEventListener('change', () => {
            const id = Number(cb.getAttribute('data-id') || 0);
            if (!id) return;
            if (cb.checked) selectedPoster.add(id);
            else selectedPoster.delete(id);
            updateLinkButtonState();
        });
    });
"""
    indented_poster_cb = "\n".join("    " + line if line.strip() else line for line in poster_cb_code.split('\n'))

    new_block = "    const bindInTableEvents = () => {\n" + indented_poster_cb + indented_block + "    };\n    bindInTableEvents();\n\n"
    
    content = content[:start_idx] + new_block + content[end_idx:]

with open('/workspace/payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)
