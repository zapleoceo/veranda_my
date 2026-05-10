<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

class Logger {
    private string $file;

    public function __construct(string $dir, string $fileName = 'testai.log') {
        $dir = rtrim(trim($dir), '/\\');
        if ($dir === '') $dir = '.';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->file = $dir . '/' . ltrim($fileName, '/\\');
    }

    public function filePath(): string {
        return $this->file;
    }

    public function info(string $event, array $ctx = []): void {
        $this->write('info', $event, $ctx);
    }

    public function error(string $event, array $ctx = []): void {
        $this->write('error', $event, $ctx);
    }

    private function write(string $level, string $event, array $ctx): void {
        $line = json_encode([
            'ts'    => date('c'),
            'level' => $level,
            'event' => $event,
            'ctx'   => $this->sanitize($ctx),
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) return;
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function sanitize(array $ctx): array {
        $out = [];
        foreach ($ctx as $k => $v) {
            $key = is_string($k) ? $k : (string)$k;
            $lk  = strtolower($key);
            if (str_contains($lk, 'token') || str_contains($lk, 'secret') || str_contains($lk, 'pass') || str_contains($lk, 'key')) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($v) || $v === null) { $out[$key] = $v; continue; }
            if (is_array($v)) { $out[$key] = $this->sanitize($v); continue; }
            $out[$key] = '[' . gettype($v) . ']';
        }
        return $out;
    }
}
