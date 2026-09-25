<?php

declare(strict_types=1);

namespace App\Payday3\Http;

/**
 * Import map for payday3's ES modules — per-file cache busting.
 *
 * The modules import each other with plain static specifiers
 * (`import { api } from '../api.js'`), which resolve to query-less URLs
 * like /payday3/assets/js/api.js. nginx caches those for hours, so
 * content.php emits this map right before the module <script>: every
 * /payday3/assets/js/**.js URL maps to itself plus ?v=<filemtime>, and a
 * module is re-fetched exactly when its own file changes.
 *
 * (Replaces the top-level-await dynamic-import cascade that hung iOS
 * WebKit on page load.)
 */
final class ModuleImportMap
{
    public const URL_PREFIX = '/payday3/assets/js/';

    /** @var array<string, array<string, string>> dir => imports, per request */
    private static array $cache = [];

    /**
     * '/payday3/assets/js/x.js' => '/payday3/assets/js/x.js?v=<mtime>'
     * for every .js file under $dir (recursively), sorted by key.
     *
     * @return array<string, string>
     */
    public static function imports(?string $dir = null, string $urlPrefix = self::URL_PREFIX): array
    {
        $dir ??= __DIR__ . '/../../../payday3/assets/js';
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            return [];
        }
        $cacheKey = $real . '|' . $urlPrefix;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $imports = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($real) + 1));
            $url = $urlPrefix . $rel;
            $mtime = @filemtime($file->getPathname());
            $imports[$url] = $url . '?v=' . ($mtime !== false ? (string)$mtime : '1');
        }
        ksort($imports, SORT_STRING);

        return self::$cache[$cacheKey] = $imports;
    }

    /** JSON body for <script type="importmap">. */
    public static function json(?string $dir = null, string $urlPrefix = self::URL_PREFIX): string
    {
        $imports = self::imports($dir, $urlPrefix);
        return (string)json_encode(
            ['imports' => $imports === [] ? new \stdClass() : $imports],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }
}
