# Refactoring Guide — veranda.my

> Single source of truth for the PHP 8.2 + Slim 4 refactoring.  
> Update this file as phases complete. Every architectural decision goes here.

---

## Goal

Migrate the project from procedural PHP 7.4 to a structured PHP 8.2 + Slim 4 application
with proper MVC separation, PSR-4 autoloading, dependency injection, and full PHPUnit coverage.

---

## Quick facts

| Item | Value |
|------|-------|
| PHP target | 8.2 (PHP-FPM via FastPanel `/opt/php82/bin/php`) |
| Framework | Slim 4 + PHP-DI 7 |
| Autoload | PSR-4 via Composer (`App\` → `src/`) |
| Tests | PHPUnit 11 |
| Logger | Monolog 3 (PSR-3) |
| Production branch | `main` |
| Refactor branch | `refactor/slim4` |
| Staging URL | https://beta.veranda.my |
| Production URL | https://veranda.my |

---

## Branch strategy

```
main                  ← production, deployed by deploy.yml on push
│
└── refactor/slim4    ← staging, deployed by deploy-staging.yml on push
    ├── refactor/phase-1-foundation
    ├── refactor/phase-2-telegram
    ├── refactor/phase-3-admin
    ├── refactor/phase-4-modules
    ├── refactor/phase-5-payday2
    └── refactor/phase-6-wa-listener
```

**Rule:** every phase gets its own branch off `refactor/slim4`, is reviewed and tested on
staging, then merged back via PR. `main` is only touched on final cutover.

**Rollback:** `git revert` the merge commit on `main` → push → auto-deploys in ~1 min.

---

## New directory structure

```
veranda.my/
├── public/                     ← document root (nginx points here)
│   ├── index.php               ← Slim entry point
│   └── assets/                 ← static files
│
├── src/
│   ├── Bootstrap/
│   │   ├── app.php             ← Slim factory, middleware stack
│   │   ├── container.php       ← PHP-DI service definitions
│   │   └── routes.php          ← all routes in one place
│   │
│   ├── Infrastructure/
│   │   ├── Config.php          ← .env loader — ONE instance, replaces 8+ duplicates
│   │   ├── Database.php        ← PDO singleton
│   │   ├── HttpClient.php      ← curl abstraction — replaces 313 duplicates
│   │   └── Logger.php          ← PSR-3 Monolog singleton
│   │
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── WebhookSecretMiddleware.php
│   │
│   ├── Controllers/
│   │   ├── Admin/              ← one controller per admin tab
│   │   ├── Auth/               ← login, callback, logout
│   │   └── WebhookController.php
│   │
│   ├── Actions/                ← Telegram webhook callback handlers
│   │   ├── ActionInterface.php
│   │   ├── IgnoreItemAction.php
│   │   ├── IgnoreTxAction.php
│   │   ├── VposterAction.php
│   │   ├── VposterFixAction.php
│   │   ├── VposterCancelAction.php
│   │   ├── VdeclineAction.php
│   │   └── VrestoreAction.php
│   │
│   ├── Services/
│   │   ├── TelegramAlertService.php   ← was telegram_alerts.php (782 lines)
│   │   ├── KitchenSyncService.php     ← was cron.php (548 lines)
│   │   ├── MenuSyncService.php
│   │   ├── ReservationService.php
│   │   ├── AuthService.php
│   │   └── Payday/
│   │       ├── PaydayService.php
│   │       ├── FinanceReportService.php
│   │       └── SalaryService.php
│   │
│   ├── Repositories/
│   │   ├── AlertItemRepository.php
│   │   ├── TransactionRepository.php
│   │   └── ReservationRepository.php
│   │
│   └── Models/                 ← value objects / readonly data classes
│       ├── AlertItem.php
│       └── Transaction.php
│
├── templates/                  ← HTML views (extracted from inline PHP)
│   ├── admin/
│   ├── tr3/
│   ├── reservations/
│   ├── links/
│   └── payday/
│
├── cron/                       ← thin crontab entry points
│   ├── kitchen_sync.php        ← bootstrap + KitchenSyncService->run()
│   ├── telegram_alerts.php     ← bootstrap + TelegramAlertService->run()
│   ├── menu_sync.php
│   └── daily_summary.php
│
├── wa_listener/                ← Node.js WhatsApp bridge (restructured)
│   ├── src/
│   │   ├── index.js
│   │   ├── handlers/
│   │   └── config.js
│   └── package.json
│
├── tests/
│   ├── Unit/
│   ├── Feature/
│   └── bootstrap.php
│
├── composer.json
├── phpunit.xml
└── .env
```

---

## Coding conventions

### PHP
- `declare(strict_types=1)` in every file
- `readonly` properties where value never changes after construction
- `X|null` not `?X` for nullable (both work, prefer `X|null` for clarity at top level)
- No `static` outside singletons — inject dependencies
- Services: `run()` as entry point for cron, `handle()` for request-scoped
- Repositories: only DB access — no business logic, no HTTP calls
- Controllers: validate input → call service → return response

### Templates
- Plain PHP, no templating engine
- `htmlspecialchars()` on every user-supplied output: `e($value)` helper in `src/helpers.php`
- No business logic in templates

### Tests
- Every Service method has at least one unit test
- Use `Database` mock or separate test DB (`DB_TABLE_SUFFIX=_test`)
- Feature tests use Slim's `ServerRequestInterface` directly (no HTTP calls needed)

---

## Phase progress

| Phase | Status | Notes |
|-------|--------|-------|
| 0 — Setup | ✅ Done | Branch, structure, composer.json, deploy-staging.yml, REFACTORING.md |
| 1 — Foundation | ✅ Done | Config, HttpClient (+headers), Logger, Database, TelegramBotClient, AuthMiddleware, WebhookSecretMiddleware, PHPUnit baseline (14 tests) |
| 2a — Telegram alerts | ✅ Done | TelegramAlertService, ReservationReminderService, AlertItemRepository, MetaRepository, AlertItem, cron/telegram_alerts.php, setWebhook removed |
| 2b — Kitchen sync | 🔄 In progress | KitchenSyncService from cron.php |
| 2c — Webhook actions | ⏳ Pending | WebhookController + ActionInterface + 7 action classes |
| 2d — Cron entry points | ⏳ Pending | kitchen_sync.php, menu_sync.php, daily_summary.php |
| 3 — Admin panel | ⏳ Pending | Slim routes, controllers, templates/ |
| 4 — Modules | ⏳ Pending | tr3, reservations, links, kitchen_online |
| 5 — payday2 | ⏳ Pending | Split ajax.php (111K) + view.php (83K) into controllers/services/templates |
| 6 — wa_listener | ⏳ Pending | Node.js src/ restructure |
| 7 — Cutover | ⏳ Pending | Staging smoke tests → merge refactor/slim4 → main |

---

## Server setup required (one-time, manual)

Before staging works, on the server:

```bash
# 1. Verify PHP 8.2 is available
ls /opt/ | grep php

# 2. Create staging document root
mkdir -p /var/www/beta_veranda_my

# 3. Create FastPanel vhost: beta.veranda.my
#    - Root: /var/www/beta_veranda_my/public
#    - PHP handler: PHP-FPM
#    - PHP version: 8.2
#    - SSL: Cloudflare Flexible (same as prod)

# 4. Add STAGING_DEPLOY_PATH = /var/www/beta_veranda_my to GitHub Secrets

# 5. Install Composer on server (if not present)
curl -sS https://getcomposer.org/installer | /opt/php82/bin/php -- --install-dir=/usr/local/bin --filename=composer

# 6. Copy .env from prod and adjust for staging
cp /var/www/veranda_my/.env /var/www/beta_veranda_my/.env
# Edit: APP_ENV=development, TELEGRAM_WEBHOOK_SECRET=<different>
```

---

## Known migrations needed

| Old code | New code | Why |
|----------|----------|-----|
| 8× `.env` loader blocks | `Config::load()` once in `app.php` | DRY |
| 313× curl init blocks | `HttpClient->postJson()` | DRY + retry logic |
| `require auth_check.php` everywhere | `AuthMiddleware` | proper middleware |
| `$_GET['tab']` routing in admin | Slim routes | explicit routing |
| Naked `setWebhook` in `telegram_alerts.php` | remove entirely | runs every minute, wasteful |
| `telegram_alerts.php` (782 lines) | `TelegramAlertService` | SRP |
| `cron.php` (548 lines) | `KitchenSyncService` | SRP |
| `payday2/ajax.php` (111K) | multiple controllers + services | SRP |
| `payday2/view.php` (83K) | templates/payday/ partials | SRP |

---

## GitHub Actions secrets needed

| Secret | Value |
|--------|-------|
| `SSH_PRIVATE_KEY` | already set |
| `SSH_HOST` | already set |
| `SSH_PORT` | already set |
| `SSH_USER` | already set |
| `SSH_KNOWN_HOSTS` | already set |
| `DEPLOY_PATH` | production path (already set) |
| `STAGING_DEPLOY_PATH` | `/var/www/beta_veranda_my` ← **add this** |
