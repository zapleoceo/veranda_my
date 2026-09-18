<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Origin / Referer / Sec-Fetch-Site checks shared by the CSRF guards.
 *
 * Returns null when the request looks same-origin, otherwise a short
 * machine-readable reason ('no-host', 'no-origin', 'cross-origin',
 * 'fetch-site') that the middleware echoes in its 403 body.
 */
final class SameOrigin
{
    /**
     * @param bool $requireHeader true → a request with neither Origin nor
     *                            Referer is rejected ('no-origin'); false →
     *                            such a request passes (headers are only
     *                            checked "when present").
     */
    public static function violation(ServerRequestInterface $request, bool $requireHeader): ?string
    {
        $host = $request->getHeaderLine('Host');
        if ($host === '') $host = $request->getUri()->getHost();
        if ($host === '') return 'no-host';
        $hostBase = strtolower((string)preg_replace('/:\d+$/', '', $host));

        $sources = [];
        $origin  = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') $sources[] = $origin;
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') $sources[] = $referer;

        if ($sources === []) {
            if ($requireHeader) return 'no-origin';
        } else {
            // Either header pointing at our host is enough (Origin is
            // missing on some same-tab navigations).
            $matched = false;
            foreach ($sources as $src) {
                $h = parse_url($src, PHP_URL_HOST);
                if (is_string($h) && strtolower($h) === $hostBase) { $matched = true; break; }
            }
            if (!$matched) return 'cross-origin';
        }

        // Fetch metadata (best-effort, modern browsers only).
        $sfs = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        if ($sfs !== '' && !in_array($sfs, ['same-origin', 'same-site'], true)) {
            return 'fetch-site';
        }
        return null;
    }
}
