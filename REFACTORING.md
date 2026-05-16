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
│   │   ├── Database.php        ← PDO singleton with table prefix helper t()
│   │   ├── HttpClient.php      ← curl abstraction — replaces 313 duplicates
│   │   └── Logger.php          ← PSR-3 Monolog singleton
│   │
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── WebhookSecretMiddleware.php
│   │
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── DashboardController.php   ← Kitchen wait-time charts, date range filter
│   │   │   ├── AccessController.php      ← User CRUD + 14 permission toggles
│   │   │   ├── LogsController.php        ← Log tail viewer + manual sync runner
│   │   │   ├── ReservationsAdminController.php ← Reservation config (system_meta)
│   │   │   ├── SyncController.php        ← 4 sync job statuses + manual trigger
│   │   │   ├── TelegramAdminController.php    ← Alert settings + AJAX test send
│   │   │   └── MenuController.php        ← Menu list/edit/publish, Poster sync
│   │   │
│   │   ├── Auth/
│   │   │   ├── LoginController.php       ← Google OAuth redirect
│   │   │   └── CallbackController.php    ← OAuth code exchange + session
│   │   │
│   │   └── WebhookController.php
│   │
│   ├── Views/
│   │   ├── layout.php                    ← Shared dark-theme HTML shell (nav + flash)
│   │   └── admin/
│   │       ├── dashboard.php             ← Chart.js bar+line charts
│   │       ├── access.php                ← User table + permissions modal
│   │       ├── logs.php                  ← Log viewer + sync runner
│   │       ├── reservations.php          ← Config form
│   │       ├── sync.php                  ← Job status cards + manual run
│   │       ├── telegram.php              ← Alert settings + test form
│   │       ├── menu_list.php             ← Filterable table + inline publish toggles
│   │       └── menu_edit.php             ← 4-language translation editor
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
| 2b — Kitchen sync | ✅ Done | KitchenSyncService from cron.php (548 lines) |
| 2c — Webhook actions | ✅ Done | WebhookController + ActionInterface + 7 action classes (Ignore, Vposter, Vdecline, Vrestore) |
| 2d — Cron entry points | ✅ Done | kitchen_sync.php, menu_sync.php, daily_summary.php |
| 3 — Admin panel | ✅ Done | 7 controllers + 8 views, dark theme, Google OAuth login, Chart.js dashboard, Menu CRUD with Poster sync |
| 4 — Modules | ✅ Done | kitchen_online, rawdata, links/menu, tr3, reservations |
| 5 — payday2 | ✅ Done | Slim 4 wrapper: Payday2Controller::dispatch, slim-mode helpers in functions.php (payday2_json_header/http_code/do_exit/redirect), auth guards in ajax.php/post.php/index.php |
| 6 — wa_listener | ⏳ Pending | Node.js src/ restructure |
| 7 — Cutover | ⏳ Pending | Staging smoke tests → merge refactor/slim4 → main |

---

## Server setup (actual — FastPanel single-account)

The server runs FastPanel with a single user account `veranda_my_usr`. Both production and
staging live under the same account:

| Site | Document root | URL |
|------|--------------|-----|
| Production | `/var/www/veranda_my_usr/data/www/veranda.my/` | https://veranda.my |
| Staging | `/var/www/veranda_my_usr/data/www/veranda.my/beta/public/` | https://beta.veranda.my |

**SSL**: Cloudflare **Flexible** mode — origin serves HTTP on port 80. Do NOT switch to
Full/Full Strict; port 443 nginx config redirects to index.html and breaks Slim routing.

**PHP binary**: `/opt/php82/bin/php` (for cron scripts and CLI tools).

**`.env` location**: `/var/www/veranda_my_usr/data/www/veranda.my/beta/.env`
(excluded from rsync by deploy workflow — never committed to repo).

**GitHub Actions secrets used by `deploy-staging.yml`**:

| Secret | Value |
|--------|-------|
| `STAGING_SSH_PRIVATE_KEY` | ED25519 key for `veranda_my_usr@5.101.179.132` |
| `STAGING_KNOWN_HOSTS` | server host key fingerprint |
| `STAGING_SSH_HOST` | `5.101.179.132` |
| `STAGING_SSH_USER` | `veranda_my_usr` |
| `STAGING_SSH_PORT` | `22` |
| `STAGING_DEPLOY_PATH` | `/var/www/veranda_my_usr/data/www/veranda.my/beta` |

**SSH key injection**: use `printf '%s\n'` (not `echo`) to preserve ED25519 key newlines.

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
