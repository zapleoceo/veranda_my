<?php

namespace App\Classes;

class TestAIEnvLoader {
    public function load(string $envFile): array {
        $loaded = [];
        if (!is_file($envFile)) return $loaded;

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (is_array($lines) ? $lines : [] as $line) {
            $t = trim((string)$line);
            if ($t === '' || strpos($t, '#') === 0 || strpos($t, '=') === false) continue;
            [$name, $value] = explode('=', $t, 2);
            $k = trim((string)$name);
            $k = preg_replace('/^\xEF\xBB\xBF/', '', $k) ?? $k;
            if ($k === '') continue;
            $raw = trim((string)$value);
            if ($raw !== '' && $raw[0] !== '"' && $raw[0] !== "'") {
                $raw = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;
                $raw = trim($raw);
            }
            if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
                $raw = substr($raw, 1, -1);
                $raw = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $raw);
            } elseif (strlen($raw) >= 2 && $raw[0] === "'" && substr($raw, -1) === "'") {
                $raw = substr($raw, 1, -1);
            }
            $_ENV[$k] = $raw;
            $loaded[$k] = true;
        }
        return $loaded;
    }
}

class TestAIConfig {
    public string $root;
    public string $envFile;
    public array $envLoadedKeys;
    public string $spotTzName;
    public string $apiTzName;

    public string $dbHost;
    public string $dbName;
    public string $dbUser;
    public string $dbPass;
    public string $dbTableSuffix;

    public string $tgToken;
    public string $geminiKey;
    public string $geminiModel;
    public ?array $allowedChatIds;
    public string $adminKey;

    public string $appUrl;
    public string $geminiProxyUrl;
    public string $geminiProxyKey;

    public static function fromEnv(string $root, string $envFile, array $envLoadedKeys): self {
        $cfg = new self();
        $cfg->root = $root;
        $cfg->envFile = $envFile;
        $cfg->envLoadedKeys = $envLoadedKeys;

        $spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
        if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) $spotTzName = 'Asia/Ho_Chi_Minh';
        $apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
        if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) $apiTzName = $spotTzName;
        $cfg->spotTzName = $spotTzName;
        $cfg->apiTzName = $apiTzName;

        $cfg->dbHost = (string)($_ENV['DB_HOST'] ?? 'localhost');
        $cfg->dbName = (string)($_ENV['DB_NAME'] ?? 'veranda_my');
        $cfg->dbUser = (string)($_ENV['DB_USER'] ?? 'veranda_my');
        $cfg->dbPass = (string)($_ENV['DB_PASS'] ?? '');
        $cfg->dbTableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

        $cfg->tgToken = (string)($_ENV['ai_tg_bot'] ?? '');
        $cfg->geminiKey = (string)($_ENV['gemini_key'] ?? '');
        $cfg->geminiModel = trim((string)($_ENV['TESTAI_GEMINI_MODEL'] ?? 'gemini-2.5-flash'));
        $cfg->adminKey = (string)($_ENV['TESTAI_ADMIN_KEY'] ?? '');

        $allowedChatsRaw = trim((string)($_ENV['TESTAI_ALLOWED_CHAT_IDS'] ?? ''));
        $allowed = null;
        if ($allowedChatsRaw !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $allowedChatsRaw)), fn($x) => $x !== ''));
            $allowed = $ids ? array_fill_keys($ids, true) : null;
        }
        $cfg->allowedChatIds = $allowed;

        $cfg->appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $cfg->geminiProxyUrl = rtrim((string)($_ENV['GEMINI_PROXY_URL'] ?? ''), '/');
        $cfg->geminiProxyKey = (string)($_ENV['GEMINI_PROXY_KEY'] ?? '');
        if ($cfg->geminiProxyKey === '') $cfg->geminiProxyKey = (string)($_ENV['nGEMINI_PROXY_KEY'] ?? '');
        if ($cfg->geminiProxyKey === '') $cfg->geminiProxyKey = (string)($_ENV['CLOUDFLARE_TURN_API_TOKEN'] ?? '');

        return $cfg;
    }

    public function applyTimezone(): void {
        if ($this->apiTzName !== '') date_default_timezone_set($this->apiTzName);
    }

    public function geminiProxyBase(): string {
        if ($this->geminiProxyUrl !== '') return $this->geminiProxyUrl;
        if ($this->appUrl === '' || !preg_match('#^https?://#i', $this->appUrl)) return '';
        return $this->appUrl . '/__gemini';
    }

    public function canCallGemini(): bool {
        return $this->geminiProxyBase() !== '' || trim($this->geminiKey) !== '';
    }
}

