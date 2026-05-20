<?php

declare(strict_types=1);

namespace App\Order\Services;

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

    public function __construct(private readonly PosterApiProviderInterface $poster)
    {
        $this->token         = trim((string)($_ENV['POSTER_API_TOKEN'] ?? getenv('POSTER_API_TOKEN') ?: ''));
        $envSpot             = $_ENV['POSTER_SPOT_ID'] ?? getenv('POSTER_SPOT_ID');
        $this->defaultSpotId = is_numeric($envSpot) ? max(1, (int)$envSpot) : 1;
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
            'guestsCount' => 1,
            'serviceMode' => 1,                       // dine-in
            'products'    => array_map(fn(CartLine $l) => $this->lineToOrderProduct($l), $lines),
        ];
        $comment = trim($comment);
        if ($comment !== '') $payload['comment'] = $comment;

        $url = 'https://joinposter.com/api/orders?token=' . rawurlencode($this->token);
        [$httpCode, $body] = $this->postJson($url, $payload);

        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Poster API: invalid JSON (http=' . $httpCode . ')');
        }
        if ($httpCode < 200 || $httpCode > 299) {
            throw new \RuntimeException('Poster API: ' . $this->extractError($j, $httpCode, $body));
        }
        $orderId = (int)($j['response']['id'] ?? 0);
        if ($orderId <= 0) {
            throw new \RuntimeException('Не удалось создать заказ в Poster');
        }
        return ['order_id' => $orderId];
    }

    public function appendToTransaction(int $spotId, int $tabletId, int $transactionId, string $comment, array $lines): array
    {
        if ($transactionId <= 0) throw new \InvalidArgumentException('transaction_id required');
        if ($tabletId      <= 0) throw new \InvalidArgumentException('spot_tablet_id required');
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
