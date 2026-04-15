import re

with open('assets/css/reservations.css', 'r') as f:
    content = f.read()

# Let's ensure the table wrapper is completely constrained to max-width 100vw
# Currently we have .container in app.css maybe?

content += """
@media (max-width: 720px) {
  .res-page { padding: 12px; overflow-x: hidden; }
  .res-actions { flex-wrap: wrap; width: 100%; }
}
"""

with open('assets/css/reservations.css', 'w') as f:
    f.write(content)
