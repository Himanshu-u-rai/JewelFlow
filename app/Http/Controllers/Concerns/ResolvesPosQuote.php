<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Invoice;
use App\Models\PosQuote;
use App\Services\PricingEngine;
use App\Services\PricingEngine\QuoteInput;

/**
 * Shared helper for sale endpoints that accept an optional signed quote_id.
 *
 * Used by both the web POS controller and the mobile API POS controller so
 * the quote → consume → idempotency flow is identical across clients.
 */
trait ResolvesPosQuote
{
    /**
     * @return array{
     *   quote?: PosQuote|null,
     *   breakdown?: array|null,
     *   idempotent_response?: \Illuminate\Http\JsonResponse,
     *   stale_response?: \Illuminate\Http\JsonResponse,
     * }
     */
    protected function resolveSaleQuote(int $shopId, ?string $quoteId, ?string $signature, ?int $userId = null): array
    {
        if (! $quoteId) {
            return ['quote' => null];
        }

        $quote = PosQuote::where('shop_id', $shopId)
            ->where('quote_id', $quoteId)
            ->first();

        if (! $quote) {
            return ['stale_response' => response()->json([
                'error'   => 'quote_not_found',
                'message' => 'Quote not recognised. Refresh the cart to get a fresh price.',
            ], 409)];
        }

        if ($quote->isConsumed()) {
            $invoice = Invoice::where('shop_id', $shopId)->find($quote->consumed_invoice_id);
            if ($invoice) {
                return ['idempotent_response' => response()->json([
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'idempotent'     => true,
                ])];
            }
        }

        if ($quote->isExpired()) {
            return ['stale_response' => $this->staleQuoteResponse($quote, $userId)];
        }

        if ($signature !== null && $signature !== '') {
            $engine = app(PricingEngine::class);
            if (! $engine->verify($quote->breakdown_json, $signature)) {
                return ['stale_response' => response()->json([
                    'error'   => 'invalid_signature',
                    'message' => 'Quote signature did not verify. Refresh to get a fresh quote.',
                ], 401)];
            }
        }

        // Bit-comparable recompute: if the canonical JSON drifted (item price
        // changed, offer expired, etc.) the quote is stale → fresh one returned.
        $engine     = app(PricingEngine::class);
        $recomputed = $engine->recompute($quote);
        [$recomputedJson, ] = $engine->sign($recomputed);

        if ($recomputedJson !== $quote->breakdown_json) {
            return ['stale_response' => $this->staleQuoteResponse($quote, $userId)];
        }

        return [
            'quote'     => $quote,
            'breakdown' => $quote->breakdown(),
        ];
    }

    protected function staleQuoteResponse(PosQuote $quote, ?int $userId = null): \Illuminate\Http\JsonResponse
    {
        $engine = app(PricingEngine::class);
        $issued = $engine->quote(
            QuoteInput::fromArray($quote->input_payload),
            $userId,
        );

        return response()->json([
            'error'     => 'quote_stale',
            'message'   => 'Prices were refreshed. Please review the updated total and confirm.',
            'new_quote' => [
                'quote_id'       => $issued['quote_id'],
                'signature'      => $issued['signature'],
                'expires_at'     => $issued['expires_at']->toIso8601String(),
                'breakdown'      => $issued['breakdown']->toApiArray(),
                'breakdown_json' => $issued['breakdown_json'],
            ],
        ], 409);
    }
}
