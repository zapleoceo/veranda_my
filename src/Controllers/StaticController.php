<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StaticController
{
    private const MIME = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'ico'   => 'image/x-icon',
        'json'  => 'application/json',
    ];

    private function serve(ResponseInterface $response, string $baseDir, string $relativePath): ResponseInterface
    {
        $base = realpath($baseDir);
        if ($base === false) {
            return $response->withStatus(404);
        }

        $resolved = realpath($base . DIRECTORY_SEPARATOR . $relativePath);
        if ($resolved === false || !str_starts_with($resolved, $base) || !is_file($resolved)) {
            return $response->withStatus(404);
        }

        $ext  = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'application/octet-stream';

        // Source files we own (JS/CSS) need to invalidate on every
        // deploy — we can't tolerate a 24-hour stale window.  Send
        // `must-revalidate` so the browser always asks, plus Last-
        // Modified / ETag so the answer is usually a cheap 304.
        $mtime = @filemtime($resolved) ?: 0;
        $isSource = in_array($ext, ['js', 'css', 'json'], true);
        $cacheCtl = $isSource
            ? 'public, max-age=0, must-revalidate'
            : 'public, max-age=86400';

        $lastModified = $mtime > 0 ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : null;
        $etag         = $mtime > 0 ? sprintf('"%x-%x"', $mtime, filesize($resolved) ?: 0) : null;

        // Conditional GET — return 304 when the file hasn't changed.
        $req = $_SERVER ?? [];
        $ifNoneMatch     = trim((string)($req['HTTP_IF_NONE_MATCH']     ?? ''));
        $ifModifiedSince = trim((string)($req['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        $notModified =
            ($etag         !== null && $ifNoneMatch     === $etag) ||
            ($lastModified !== null && $ifModifiedSince === $lastModified);

        if ($notModified) {
            $res = $response->withStatus(304)->withHeader('Cache-Control', $cacheCtl);
            if ($etag         !== null) $res = $res->withHeader('ETag',          $etag);
            if ($lastModified !== null) $res = $res->withHeader('Last-Modified', $lastModified);
            return $res;
        }

        $body = file_get_contents($resolved);
        $response->getBody()->write((string)$body);
        $response = $response
            ->withHeader('Content-Type',  $mime)
            ->withHeader('Cache-Control', $cacheCtl);
        if ($etag         !== null) $response = $response->withHeader('ETag',          $etag);
        if ($lastModified !== null) $response = $response->withHeader('Last-Modified', $lastModified);
        return $response;
    }

    public function globalAssets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../assets', $args['file']);
    }

    public function tr3Assets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../tr3/assets', $args['file']);
    }

    public function linksStatic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../links', $args['file']);
    }

    public function reservationsRoot(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../reservations', $args['file']);
    }

    public function reservationsAssets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../reservations/assets', $args['file']);
    }

    public function payday2Assets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../payday2/assets', $args['file']);
    }

    public function payday3Assets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../payday3/assets', $args['file']);
    }

    public function neworderAssets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../neworder/assets', $args['file']);
    }

    public function scheduleAssets(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../schedule/assets', $args['file']);
    }

    public function banyaStatic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../banya', $args['file']);
    }

    public function romaStatic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../roma', $args['file']);
    }

    public function employeesStatic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->serve($response, __DIR__ . '/../../employees', $args['file']);
    }
}
