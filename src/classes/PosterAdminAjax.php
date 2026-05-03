<?php
declare(strict_types=1);

namespace App\Classes;

final class PosterAdminAjax
{
    private string $account;
    private string $posSession;
    private string $ssid;
    private string $csrf;
    private string $cookieRaw;
    private string $userAgent;
    private ?string $lastBody = null;
    private int $lastCode = 0;

    public function __construct(array $cfg)
    {
        $this->account = trim((string)($cfg['account'] ?? ''));
        $this->posSession = trim((string)($cfg['pos_session'] ?? ''));
        $this->ssid = trim((string)($cfg['ssid'] ?? ''));
        $this->csrf = trim((string)($cfg['csrf'] ?? ''));
        $this->cookieRaw = trim((string)($cfg['cookie'] ?? ''));
        $this->userAgent = trim((string)($cfg['user_agent'] ?? ''));
        if ($this->userAgent === '') {
            $this->userAgent =
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
                '(KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
        }
        if ($this->cookieRaw === '') {
            if ($this->account === '' || $this->posSession === '' || $this->ssid === '' || $this->csrf === '') {
                throw new \RuntimeException('Poster Admin: не настроены cookies (cookie или account/pos_session/ssid/csrf).');
            }
        } else {
            $this->cookieRaw = preg_replace('/^\s*Cookie:\s*/i', '', $this->cookieRaw) ?: $this->cookieRaw;
        }
    }

    public function getAccount(): string
    {
        return $this->account;
    }

    public function getPosSession(): string
    {
        return $this->posSession;
    }

    public function getSsid(): string
    {
        return $this->ssid;
    }

    public function getCsrf(): string
    {
        return $this->csrf;
    }

    private function baseUrl(): string
    {
        return "https://{$this->account}.joinposter.com";
    }

    private function cookieHeader(): string
    {
        if ($this->cookieRaw !== '') {
            $raw = $this->cookieRaw;
            if ($this->account !== '' && !preg_match('/(?:^|;\s*)account_url=/i', $raw)) {
                $raw = rtrim($raw);
                if ($raw !== '' && !str_ends_with($raw, ';')) $raw .= ';';
                $raw .= ' account_url=' . rawurlencode($this->account);
            }
            return $raw;
        }
        return http_build_query([
            'pos_session' => $this->posSession,
            'ssid' => $this->ssid,
            'csrf_cookie_poster' => $this->csrf,
            'account_url' => $this->account,
        ], '', '; ', PHP_QUERY_RFC3986);
    }

    private function request(string $method, string $path, array $fields = []): array
    {
        $url = $this->baseUrl() . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Accept-Language: ru',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Origin: ' . $this->baseUrl(),
                'Referer: ' . $this->baseUrl() . '/manage/dash/receipts',
                'User-Agent: ' . $this->userAgent,
                'X-Requested-With: XMLHttpRequest',
                'Cookie: ' . $this->cookieHeader(),
            ],
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields ? http_build_query($fields) : '');
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Poster Admin cURL error: {$err}");
        }

        $this->lastCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $this->lastBody = $body;
        curl_close($ch);

        if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $m)) {
            $loc = trim((string)($m[1] ?? ''));
            if ($this->lastCode >= 300 && $this->lastCode < 400) {
                throw new \RuntimeException("Poster Admin redirect HTTP {$this->lastCode} to {$loc} (session expired?)");
            }
        }

        $newCsrf = null;
        $newPos = null;
        if (preg_match_all('/^Set-Cookie:\s*csrf_cookie_poster=([^;]+)/mi', $rawHeaders, $m)) {
            $newCsrf = (string)end($m[1]);
            $this->csrf = $newCsrf;
        }
        if (preg_match_all('/^Set-Cookie:\s*pos_session=([^;]+)/mi', $rawHeaders, $m)) {
            $newPos = (string)end($m[1]);
            $this->posSession = $newPos;
        }
        if ($this->cookieRaw !== '' && ($newCsrf !== null || $newPos !== null)) {
            $raw = $this->cookieRaw;
            if ($newCsrf !== null) {
                if (preg_match('/(?:^|;\s*)csrf_cookie_poster=[^;]*/i', $raw)) {
                    $raw = preg_replace('/((?:^|;\s*)csrf_cookie_poster=)[^;]*/i', '$1' . $newCsrf, $raw) ?: $raw;
                } else {
                    $raw = rtrim($raw);
                    if ($raw !== '' && !str_ends_with($raw, ';')) $raw .= ';';
                    $raw .= ' csrf_cookie_poster=' . $newCsrf;
                }
            }
            if ($newPos !== null) {
                if (preg_match('/(?:^|;\s*)pos_session=[^;]*/i', $raw)) {
                    $raw = preg_replace('/((?:^|;\s*)pos_session=)[^;]*/i', '$1' . $newPos, $raw) ?: $raw;
                } else {
                    $raw = rtrim($raw);
                    if ($raw !== '' && !str_ends_with($raw, ';')) $raw .= ';';
                    $raw .= ' pos_session=' . $newPos;
                }
            }
            $this->cookieRaw = $raw;
        }

        return [
            'code' => $this->lastCode,
            'body' => $body,
            'headers' => $rawHeaders,
        ];
    }

    public function getActions(int $txId): array
    {
        if ($txId <= 0) {
            throw new \RuntimeException('Poster Admin: invalid txId');
        }
        $r = $this->request('POST', "/listings/dash_receipts/get-actions/{$txId}");
        if ((int)$r['code'] !== 200) {
            throw new \RuntimeException("get-actions HTTP {$r['code']}: {$r['body']}");
        }
        $data = json_decode((string)$r['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException("get-actions bad JSON: {$r['body']}");
        }
        foreach ($data as $action) {
            if (is_array($action) && (string)($action['name'] ?? '') === 'edit') {
                $params = $action['params'] ?? [];
                return is_array($params) ? $params : [];
            }
        }
        throw new \RuntimeException("edit action not found for tx {$txId}");
    }

    public function editCheck(int $txId, array $newParams): array
    {
        if ($txId <= 0) {
            throw new \RuntimeException('Poster Admin: invalid txId');
        }
        $payedCash = $newParams['payedCash'] ?? $newParams['payed_cash'] ?? 0;
        $payedCard = $newParams['payedCard'] ?? $newParams['payed_card'] ?? 0;
        $payedCert = $newParams['payedCert'] ?? $newParams['payed_cert'] ?? 0;
        $payedEwallet = $newParams['payedEwallet'] ?? $newParams['payed_ewallet'] ?? 0;

        $fields = [
            'payed_cash' => max(0, (int)$payedCash),
            'payed_card' => max(0, (int)$payedCard),
            'payed_cert' => max(0, (int)$payedCert),
            'payed_ewallet' => max(0, (int)$payedEwallet),
        ];

        $r = $this->request('POST', "/manage/ajax/edit_check/{$txId}", $fields);
        if ((int)$r['code'] !== 200) {
            throw new \RuntimeException("edit_check HTTP {$r['code']}: {$r['body']}");
        }
        $json = json_decode((string)$r['body'], true);
        return is_array($json) ? $json : ['raw' => (string)$r['body']];
    }
}
