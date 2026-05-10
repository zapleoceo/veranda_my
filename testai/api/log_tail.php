<?php
declare(strict_types=1);

$requireAdmin();

$n = (int)($_GET['n'] ?? 80);
$n = max(1, min(300, $n));
$file = ($log instanceof \App\Classes\TestAILogger) ? $log->filePath() : '';
if ($file === '' || !is_file($file)) {
  $bad('missing_log_file');
  exit;
}
$tail = function (string $path, int $lines, int $maxBytes = 200000): string {
  $size = @filesize($path);
  if (!is_int($size) || $size <= 0) return '';
  $fh = @fopen($path, 'rb');
  if (!is_resource($fh)) return '';
  $read = min($maxBytes, $size);
  @fseek($fh, -$read, SEEK_END);
  $buf = (string)@fread($fh, $read);
  @fclose($fh);
  $buf = str_replace("\r\n", "\n", $buf);
  $parts = array_values(array_filter(explode("\n", $buf), fn($x) => $x !== ''));
  $slice = array_slice($parts, max(0, count($parts) - $lines));
  return implode("\n", $slice);
};
$ok(['file' => $file, 'tail' => $tail($file, $n)]);
exit;

