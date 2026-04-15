import re

with open('assets/css/reservations.css', 'r') as f:
    content = f.read()

# Make sure table elements don't overflow
content += """
@media (max-width: 720px) {
  .res-table { max-width: 100vw; overflow-x: hidden; }
  .res-table td { word-break: break-all; }
  .table-wrap { width: 100%; max-width: 100vw; overflow-x: hidden; }
}
"""

with open('assets/css/reservations.css', 'w') as f:
    f.write(content)
