<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

class HtmlSanitizer {
    /** Clean generic HTML for website display */
    public function sanitizeHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';
        if (mb_strlen($html) > 200000) $html = mb_substr($html, 0, 200000);

        $allowed = '<div><p><br><strong><b><em><i><ul><ol><li><h2><h3><a><span>';
        $html = strip_tags($html, $allowed);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        foreach ((new \DOMXPath($doc))->query('//*') as $el) {
            if (!$el instanceof \DOMElement) continue;
            $tag  = strtolower($el->tagName);
            $drop = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $val  = (string)$attr->value;
                if (str_starts_with($name, 'on')) { $drop[] = $attr->name; continue; }
                if ($tag === 'a') {
                    if (!in_array($name, ['href', 'target', 'rel'], true)) { $drop[] = $attr->name; continue; }
                    if ($name === 'href' && (trim($val) === '' || preg_match('/^\s*javascript:/i', $val))) { $drop[] = $attr->name; continue; }
                    if ($name === 'target') $el->setAttribute('target', '_blank');
                    if ($name === 'rel')    $el->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $drop[] = $attr->name;
                }
            }
            foreach ($drop as $n) $el->removeAttribute($n);
            if ($tag === 'a') {
                if (!$el->hasAttribute('rel'))    $el->setAttribute('rel', 'noopener noreferrer');
                if (!$el->hasAttribute('target')) $el->setAttribute('target', '_blank');
            }
        }

        $out = $doc->saveHTML();
        return is_string($out) ? trim($out) : '';
    }

    /** Clean HTML for Telegram (allowed tags only, no <br>, use newlines) */
    public function sanitizeTelegramHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';
        if (mb_strlen($html) > 50000) $html = mb_substr($html, 0, 50000);

        // Convert block tags to newlines before stripping
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $html = str_ireplace(['</p>', '</div>', '</li>', '</h2>', '</h3>', '</h1>', '</h4>'], "\n", $html);
        $html = preg_replace('/<\s*(p|div|ul|ol|li|h[1-4])\b[^>]*>/i', "\n", $html) ?? $html;

        // Strip all tags except the ones Telegram supports
        $allowed = '<b><strong><i><em><u><ins><s><strike><del><code><pre><a>';
        $html = strip_tags($html, $allowed);

        // Keep only href on <a>, remove all other attributes; drop javascript: hrefs
        $html = preg_replace_callback('/<a\b([^>]*)>/i', static function (array $m): string {
            if (preg_match('/\bhref\s*=\s*"([^"]*)"/i', $m[1], $hm) ||
                preg_match("/\\bhref\\s*=\\s*'([^']*)'/i", $m[1], $hm) ||
                preg_match('/\bhref\s*=\s*(\S+)/i', $m[1], $hm)) {
                $href = $hm[1];
                if (preg_match('/^\s*javascript:/i', $href)) return '';
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            }
            return '';
        }, $html) ?? $html;

        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
        return trim($html);
    }
}
