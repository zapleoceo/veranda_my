<?php

declare(strict_types=1);

// Legacy webhook URL kept for backward compatibility with bots that were
// registered against /telegram_webhook.php before the Slim refactor. We
// forward to the Slim front controller; the route alias below resolves to
// WebhookController::handle (same code path as /telegram_webhook).
//
// Note: a Telegram bot registered against the new URL doesn't reach this
// file at all. This shim exists only to avoid an irrecoverable URL break
// if some integration is still pointing here.

require __DIR__ . '/public/index.php';
