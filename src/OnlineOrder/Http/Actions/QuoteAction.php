<?php

declare(strict_types=1);

namespace App\OnlineOrder\Http\Actions;

use App\OnlineOrder\Domain\DeliveryAddress;
use App\OnlineOrder\Services\DeliveryQuoteService;
use App\Order\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /onlineorder/api/quote — live delivery-cost preview while the
 * customer fills the checkout form. Body:
 *   { address?: string, point?: {lat,lng}, apartment?, note? }
 *
 * Returns the quote plus the coordinates we resolved, so the client
 * pins down the same destination the server will re-validate at
 * submit time. CSRF-gated (mutating-verb guard + origin check).
 */
final class QuoteAction
{
    public function __construct(private readonly DeliveryQuoteService $quotes) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b = $this->readJson($request);
        if ($b === null) return JsonResponse::error($response, 'Bad JSON', 400);

        $address = DeliveryAddress::fromInput($b);
        if (!$address->isValid()) {
            return JsonResponse::error($response, 'address required', 400);
        }

        try {
            $r = $this->quotes->quoteFor($address);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, $e->getMessage(), 502);
        }

        return JsonResponse::ok($response, [
            'quote'    => $r['quote']->toJson(),
            'resolved' => $r['point']?->toArray(),
        ]);
    }

    private function readJson(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed) return $parsed;
        $raw = (string)$request->getBody();
        if ($raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }
}
