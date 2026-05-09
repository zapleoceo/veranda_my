<?php
declare(strict_types=1);

function testai_sanitize_html(string $html): string {
  $html = trim($html);
  if ($html === '') return '';
  if (mb_strlen($html) > 200000) $html = mb_substr($html, 0, 200000);

  $allowedTags = ['div','p','br','strong','b','em','i','ul','ol','li','h2','h3','a','span'];
  $allowed = '<' . implode('><', $allowedTags) . '>';
  $html = strip_tags($html, $allowed);

  $doc = new \DOMDocument('1.0', 'UTF-8');
  libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();

  $xpath = new \DOMXPath($doc);
  foreach ($xpath->query('//*') as $el) {
    if (!$el instanceof \DOMElement) continue;
    $tag = strtolower($el->tagName);
    $toRemove = [];
    foreach ($el->attributes as $attr) {
      $name = strtolower($attr->name);
      $val = (string)$attr->value;
      if (strpos($name, 'on') === 0) { $toRemove[] = $attr->name; continue; }
      if ($tag === 'a') {
        if (!in_array($name, ['href','target','rel'], true)) { $toRemove[] = $attr->name; continue; }
        if ($name === 'href') {
          $v = trim($val);
          if ($v === '' || preg_match('/^\s*javascript:/i', $v)) { $toRemove[] = $attr->name; continue; }
        }
        if ($name === 'target') {
          $el->setAttribute('target', '_blank');
        }
        if ($name === 'rel') {
          $el->setAttribute('rel', 'noopener noreferrer');
        }
      } else {
        $toRemove[] = $attr->name;
      }
    }
    foreach ($toRemove as $n) $el->removeAttribute($n);
    if ($tag === 'a') {
      if (!$el->hasAttribute('rel')) $el->setAttribute('rel', 'noopener noreferrer');
      if (!$el->hasAttribute('target')) $el->setAttribute('target', '_blank');
    }
  }

  $out = $doc->saveHTML();
  return is_string($out) ? trim($out) : '';
}

