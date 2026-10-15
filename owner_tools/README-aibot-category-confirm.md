# Finance category confirmation in @Veranda_aibot

An authorized user can send a complete, addressed expense command with four
lines: `Со счета ...`, `Сумма -1`, a category name, and `Комментарий ...`.
The bot prepares a Telegram card. It does not write to Poster until the same
user presses the current `Внести` button.

Category choices come from the current Poster finance category list and must
allow expenses. An exact name selects one category. The explicit word
`Корректировка` selects Poster category 4 only while that live category is
named `Актуализация` and allows expenses; the existing balance sync uses the
same ID. If the name cannot be resolved, the card shows the requested text and
asks the user to choose a category. A missing Poster lookup leaves the card
blocked. The account, date, amount, comment, and category remain visible for
review before confirmation.

The existing source-message key, confirmation nonce, lock, duplicate check,
Poster transaction ID, and audit path govern the write. The bot sends a short
success reply only when Poster returns a transaction ID. An uncertain result
requires reconciliation; a possible duplicate requires a separate decision.
Do not replay the old `-1 VND` request or treat its draft as a completed
transaction without a Poster record.
