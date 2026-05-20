<?php

declare(strict_types=1);

namespace App\Order\Services;

use App\Infrastructure\Config;
use App\Infrastructure\Logger;
use App\Order\Contracts\OrdersServiceInterface;
use App\Order\Domain\CartLine;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Writes orders to Poster. Two surfaces:
 *
 *   createOrder()         — fresh transaction via POST /orders (the
 *                           Incoming Orders v1 endpoint; JSON body,
 *                           not the form-encoded /api/<method> dance).
 *
 *   appendToTransaction() — existing transaction; one
 *                           transactions.addTransactionProduct call
 *                           per line, plus optional comment updates.
 *
 * Both paths share the same wire translation for modifier picks +
 * add-on modifications.
 */
final class OrdersService implements OrdersServiceInterface
{
    private string $token;
    private int    $defaultSpotId;
    private int    $defaultTabletId;
    private int    $waiterId;
    private int    $clientId;

    public function __construct(private readonly PosterApiProviderInterface $poster)
    {
        // Read token via Config first (which Bootstrap loaded from .env)
        // then $_ENV / getenv as fallbacks for CLI / test contexts.
        $this->token = trim((string)(
            Config::get('POSTER_API_TOKEN')
            ?: ($_ENV['POSTER_API_TOKEN'] ?? '')
            ?: (getenv('POSTER_API_TOKEN') ?: '')
        ));
        $envSpot             = Config::get('POSTER_SPOT_ID') ?: ($_ENV['POSTER_SPOT_ID'] ?? getenv('POSTER_SPOT_ID'));
        $this->defaultSpotId = is_numeric($envSpot) ? max(1, (int)$envSpot) : 1;

        // Hardcoded fallbacks for fields Poster's APIs require but that
        // aren't easily discoverable per-spot at runtime. These match
        // the legacy /neworder/assets/app.js constants that have been
        // shipping in production for months:
        //   waiterId   = 10   (operator waiter)
        //   clientId   = 71   (generic walk-in client)
        //   tabletId   = 1    (the single Poster tablet on spot 1)
        // spots.getSpot does NOT return spot_tablet_id, so when the
        // operator appends to an existing check we fall back to this
        // default instead of erroring out.
        $envWaiter         = Config::get('NEWORDER_WAITER_ID')      ?: ($_ENV['NEWORDER_WAITER_ID']      ?? getenv('NEWORDER_WAITER_ID'));
        $envClient         = Config::get('NEWORDER_CLIENT_ID')      ?: ($_ENV['NEWORDER_CLIENT_ID']      ?? getenv('NEWORDER_CLIENT_ID'));
        $envTablet         = Config::get('NEWORDER_SPOT_TABLET_ID') ?: ($_ENV['NEWORDER_SPOT_TABLET_ID'] ?? getenv('NEWORDER_SPOT_TABLET_ID'));
        $this->waiterId        = is_numeric($envWaiter) ? max(0, (int)$envWaiter) : 10;
        $this->clientId        = is_numeric($envClient) ? max(0, (int)$envClient) : 71;
        $this->defaultTabletId = is_numeric($envTablet) ? max(1, (int)$envTablet) : 1;
    }

    public function createOrder(int $spotId, int $tableId, string $comment, array $lines): array
    {
        if ($this->token === '') {
            throw new \RuntimeException('POSTER_API_TOKEN is not configured');
        }
        $lines = array_values(array_filter($lines, fn(CartLine $l) => $l->isValid()));
        if (!$lines) {
            throw new \InvalidArgumentException('Корзина пуста');
        }

        $payload = [
            'spotId'      => $spotId > 0 ? $spotId : $this->defaultSpotId,
            'tableId'     => $tableId > 0 ? $tableId : 0,
            'waiterId'    => $this->waiterId,         // required by Poster
            'guestsCount' => 1,
            'serviceMode' => 1,                       // dine-in
            'products'    => array_map(fn(CartLine $l) => $this->lineToOrderProduct($l), $lines),
        ];
        $comment = trim($comment);
        if ($comment !== '') $payload['comment'] = $comment;
        // Poster expects an explicit client object; the generic walk-in
        // client (id 71 in RestPublica) is the operator-side fallback.
        if ($this->clientId > 0) {
            $payload['client'] = ['id' => $this->clientId];
        }

        $url = 'https://joinposter.com/api/orders?token=' . rawurlencode($this->token);
        [$httpCode, $body] = $this->postJson($url, $payload);

        // Every Poster response goes to the error log when something
        // looks off — visible via Logger output / Apache error_log, so
        // 502s in production become diagnosable without re-deploying
        // with extra logging each time.
        $logCtx = [
            'spot_id'  => $spotId,
            'table_id' => $tableId,
            'items'    => count($lines),
            'http'     => $httpCode,
        ];

        $j = json_decode($body, true);
        if (!is_array($j)) {
            $this->logErr('createOrder: non-JSON response', $logCtx + ['body' => mb_substr($body, 0, 500)]);
            throw new \RuntimeException('Poster API: invalid JSON (http=' . $httpCode . ')');
        }
        if ($httpCode < 200 || $httpCode > 299) {
            $err = $this->extractError($j, $httpCode, $body);
            $this->logErr('createOrder: http error', $logCtx + ['err' => $err, 'body' => mb_substr($body, 0, 500)]);
            throw new \RuntimeException('Poster API: ' . $err);
        }
        // Poster sometimes returns 200 but with an `error` field
        // populated (validation errors), in which case `response.id`
        // is absent — surface that explicitly.
        if (isset($j['error']) && $j['error']) {
            $err = $this->extractError($j, $httpCode, $body);
            $this->logErr('createOrder: payload error', $logCtx + ['err' => $err, 'body' => mb_substr($body, 0, 500)]);
            throw new \RuntimeException('Poster API: ' . $err);
        }
        $orderId = (int)($j['response']['id'] ?? 0);
        if ($orderId <= 0) {
            $this->logErr('createOrder: no order_id', $logCtx + ['body' => mb_substr($body, 0, 500)]);
            throw new \RuntimeException('Poster API: пустой ответ (заказ не создан)');
        }
        return ['order_id' => $orderId];
    }

    private function logErr(string $msg, array $ctx): void
    {
        // Tolerant: if Logger isn't booted in this request, fall back to
        // error_log. Either way the operator gets a server-side trail.
        try { Logger::get()->error('[neworder/orders] ' . $msg, $ctx); }
        catch (\Throwable $_) { error_log('[neworder/orders] ' . $msg . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE)); }
    }

    public function appendToTransaction(int $spotId, int $tabletId, int $transactionId, string $comment, array $lines): array
    {
        if ($transactionId <= 0) throw new \InvalidArgumentException('transaction_id required');
        // spots.getSpot doesn't expose spot_tablet_id, so the frontend
        // can't fetch it. Fall back to the configured default — same
        // hardcoded "1" the legacy /neworder used.
        if ($tabletId <= 0) $tabletId = $this->defaultTabletId;
        $lines = array_values(array_filter($lines, fn(CartLine $l) => $l->isValid()));
        if (!$lines) throw new \InvalidArgumentException('Корзина пуста');

        $api = $this->poster->client();
        $spotIdEff = $spotId > 0 ? $spotId : $this->defaultSpotId;

        if (trim($comment) !== '') {
            $api->request('transactions.changeComment', [
                'spot_id'        => $spotIdEff,
                'spot_tablet_id' => $tabletId,
                'transaction_id' => $transactionId,
                'comment'        => trim($comment),
            ], 'POST');
        }

        // Anchor product `time` slightly after the transaction's start
        // so Poster orders the new lines AFTER existing ones — payday2
        // hit a sorting bug otherwise.
        $baseMs = $this->resolveBaseTimeMs($transactionId);

        $added = 0;
        $i     = 0;
        foreach ($lines as $l) {
            $i++;
            $time   = (string)($baseMs + $i);
            $params = [
                'spot_id'        => $spotIdEff,
                'spot_tablet_id' => $tabletId,
                'transaction_id' => $transactionId,
                'product_id'     => $l->productId,
                'num'            => $l->count,
                'time'           => $time,
            ];
            if ($l->modificatorId > 0) {
                $params['modificator_id'] = $l->modificatorId;
            }
            $modJson = $this->modificationsJson($l);
            if ($modJson !== '') $params['modification'] = $modJson;
            $api->request('transactions.addTransactionProduct', $params, 'POST');

            if ($l->comment !== '') {
                $pccParams = $params + ['comment' => $l->comment];
                unset($pccParams['num']);
                $api->request('transactions.changeProductComment', $pccParams, 'POST');
            }
            $added++;
        }
        return ['added' => $added];
    }

    private function resolveBaseTimeMs(int $txId): int
    {
        try {
            $res = $this->poster->client()->request('dash.getTransaction', [
                'transaction_id'    => $txId,
                'include_history'   => 'false',
                'include_products'  => 'false',
                'include_delivery'  => 'false',
            ], 'GET');
            $tx  = (is_array($res) && isset($res[0]) && is_array($res[0])) ? $res[0] : (is_array($res) ? $res : []);
            $dsN = (int)($tx['date_start_new'] ?? 0);
            $ds  = (int)($tx['date_start']     ?? 0);
            $txStart = $dsN > 0 ? $dsN : $ds;
            $now = (int)round(microtime(true) * 1000);
            return $txStart > 0 && $now <= $txStart ? $txStart + 1 : $now;
        } catch (\Throwable $_) {
            return (int)round(microtime(true) * 1000);
        }
    }

    /** Translate a domain cart line into the JSON-product shape Poster expects in POST /orders. */
    private function lineToOrderProduct(CartLine $l): array
    {
        $row = ['id' => $l->productId, 'count' => $l->count];
        if ($l->modificatorId > 0) $row['modificatorId'] = $l->modificatorId;
        if ($l->modifications) {
            $row['modification'] = array_map(
                static fn(array $m) => ['id' => $m['id'], 'count' => $m['count']],
                $l->modifications,
            );
        }
        if ($l->comment !== '') $row['comment'] = $l->comment;
        return $row;
    }

    /** Serialise the per-line add-on modifications for `transactions.addTransactionProduct`. */
    private function modificationsJson(CartLine $l): string
    {
        if (!$l->modifications) return '';
        $arr = [];
        foreach ($l->modifications as $m) {
            $arr[] = ['m' => $m['id'], 'a' => $m['count']];
        }
        return $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE) : '';
    }

    /** @return array{0:int,1:string} [httpCode, body] */
    private function postJson(string $url, array $payload): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new \RuntimeException('CURL error: ' . $err);
        if (!is_string($resp) || $resp === '') {
            throw new \RuntimeException('Poster API: empty response (http=' . $code . ')');
        }
        return [$code, $resp];
    }

    private function extractError(array $j, int $http, string $body): string
    {
        $msg = '';
        if (isset($j['error'])) {
            if (is_string($j['error'])) $msg = $j['error'];
            elseif (is_array($j['error'])) $msg = (string)($j['error']['message'] ?? $j['error']['msg'] ?? '');
        }
        if ($msg === '' && isset($j['message'])) $msg = (string)$j['message'];
        if ($msg === '') $msg = 'http=' . $http . ' body=' . mb_substr($body, 0, 300);
        return $msg;
    }
}
