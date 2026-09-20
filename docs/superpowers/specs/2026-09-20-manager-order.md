# Manager Order

`/neworder/` is the staff ordering page, named **Заказ Менеджера** (localized in English/Vietnamese).

Access requires a signed-in session and the `neworder` permission. Administrators inherit access; other users are denied by default. Grant access in **Admin → Access → user → Заказ Менеджера → Save**. No database migration is required: the flag uses existing `users.permissions_json`. The manager page and all five API endpoints share the gate. Rights refresh from the database on every manager request, so revocations take effect immediately. POST endpoints also retain CSRF/origin checks. The customer `/onlineorder/` flow remains public.

Categories start collapsed and sort by leading number, with unnumbered categories last. Search matches dish/category words, opens matching groups and restores manual expansion when cleared. The search field has a permanent brand accent border. Public-menu visibility flags do not filter the manager menu. The table selector says “Выбрать столик”, then displays the selected table number.

Validation: actual Slim route integration tests cover guests, default denial, cached-permission revocation, explicit grants, admin access, CSRF enforcement and public customer checkout. UI was checked at mobile and desktop widths (320–1920 pixels) with the live menu; no production orders were submitted. Production release requires Sol review before merge, successful deployment workflow and live access/UI checks.
