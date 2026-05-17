<?php
// Backward-compat shim. The real class App\Classes\ReservationTelegram now
// lives in src/classes/ReservationTelegram.php where Composer's classmap
// (configured to scan src/classes/ in composer.json) actually finds it.
// Legacy callers that did require_once 'reservations/ReservationTelegram.php'
// keep working without code changes.
require_once dirname(__DIR__) . '/src/classes/ReservationTelegram.php';
