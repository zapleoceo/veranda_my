# @Veranda_aibot: inline mode and group mentions

The finance bot handles group `message` and `callback_query` updates. It does
not provide inline search results. If BotFather has Inline Mode enabled,
Telegram treats `@Veranda_aibot` at the start of the composer as an inline
query. The client waits for inline results instead of sending a group message;
the webhook cannot fix that composer behavior.

The bot owner should open **@BotFather** in Telegram, select **/mybots →
@Veranda_aibot → Bot Settings → Inline Mode**, and turn Inline Mode off. This
does not require changing the bot token, webhook secret, or webhook. Do not
rerun `aibot_activate.sh` just to apply this BotFather setting.

Verify with the Bot API `getMe` that `supports_inline_queries` is absent or
false. The webhook should still have `allowed_updates` set to `message` and
`callback_query`. Then, in the authorized group, reply to an ordinary message
with the harmless text `@Veranda_aibot проверка` and confirm that Telegram
sends it. Do not use a financial command for this smoke test.

Until Inline Mode is turned off, placing ordinary text before the mention
(for example, `проверка @Veranda_aibot`) avoids Telegram's leading-mention
inline composer. This is a temporary client-side workaround.

`owner_tools/aibot_activate.sh` checks `getMe` before changing `.env` or the
webhook. It stops with `inline_mode_enabled` if Inline Mode is active, to catch
this configuration before a future activation.
