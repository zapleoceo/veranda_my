<?php

declare(strict_types=1);

namespace App\Payday3\Http;

use App\Payday3\Domain\Actor;

/**
 * The single place in payday3 that reads the logged-in user from
 * $_SESSION (AuthMiddleware has populated it). Actions pass the result
 * down; services stay free of superglobals.
 */
final class CurrentUser
{
    /** e-mail, or the display name for legacy sessions without one. */
    public static function email(): string
    {
        return trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
    }

    /** "Name email" label used in Telegram audit notes. */
    public static function label(): string
    {
        return trim((string)($_SESSION['user_name'] ?? '') . ' ' . (string)($_SESSION['user_email'] ?? ''));
    }

    public static function actor(): Actor
    {
        $sid = session_id();
        return new Actor(
            email:      self::email(),
            sessionKey: $sid !== false && $sid !== '' ? hash('sha256', $sid) : '',
        );
    }
}
