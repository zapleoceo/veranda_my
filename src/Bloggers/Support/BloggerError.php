<?php

declare(strict_types=1);

namespace App\Bloggers\Support;

/**
 * A user-facing validation error carrying a translation KEY (+ params) instead
 * of a baked-in language. The service throws these so it stays i18n-free; the
 * controller renders them via BloggerLang in the visitor's locale (the public
 * cabinet) or in Russian (the admin page).
 *
 * The English fallback is used as the exception message so logs stay readable.
 */
final class BloggerError extends \RuntimeException
{
    /** @param array<string,string|int|float> $params placeholder => value */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        string $fallback = '',
    ) {
        parent::__construct($fallback !== '' ? $fallback : $key);
    }
}
