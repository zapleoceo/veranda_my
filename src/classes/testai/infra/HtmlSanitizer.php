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

        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $html = str_ireplace(['</p>', '</div>', '</li>', '</h2>', '</h3>'], "\n", $html);
        $html = preg_replace('/<\s*(p|div|ul|ol|li|h2|h3)\b[^>]*>/i', "\n", $html) ?? $html;
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;

        $allowed = '<b><strong><i><em><u><ins><s><strike><del><code><pre><a>';
        $html = strip_tags($html, $allowed);
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        $token = '___TG_NL___';
        $html  = str_replace("\n", $token, $html);

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
                    if ($name !== 'href') { $drop[] = $attr->name; continue; }
                    if (trim($val) === '' || preg_match('/^\s*javascript:/i', $val)) { $drop[] = $attr->name; continue; }
                } else {
                    $drop[] = $attr->name;
                }
            }
            foreach ($drop as $n) $el->removeAttribute($n);
        }

        $out = $doc->saveHTML() ?? '';
        $out = trim($out);
        $out = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $out) ?? $out;
        $out = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $out) ?? $out;
        $out = preg_replace('/^\s*<html\b[^>]*>\s*<body\b[^>]*>\s*/i', '', $out) ?? $out;
        $out = preg_replace('/\s*<\/body>\s*<\/html>\s*$/i', '', $out) ?? $out;
        $out = str_replace($token, "\n", $out);
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
        return trim($out);
    }
}
