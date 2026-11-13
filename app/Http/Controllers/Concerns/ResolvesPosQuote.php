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
    /**
     * @param  array<int|string>  $saleItemIds  the items this sale bills
     * @param  int  $saleCustomerId  the customer this sale bills
     */
    protected function resolveSaleQuote(int $shopId, ?string $quoteId, ?string $signature, ?int $userId, array $saleItemIds, int $saleCustomerId, ?array $saleQuoteInput = null): array
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

        // Audit H4: the sale takes its money from the quote and its items and customer from the request, so the
        // two must be the same bill. Checked first: a spent quote replayed for another cart is not a double click.
        if (! $this->quoteMatchesSale($quote, $saleItemIds, $saleCustomerId)) {
            return ['stale_response' => $this->staleQuoteResponse($quote, $userId, $saleItemIds, $saleCustomerId)];
        }

        // Audit N16: a client that states the price inputs it is paying for (the web POS) must be paying for the
        // inputs this quote priced. The stored input is trustworthy: recompute() below re-prices it against the
        // signed breakdown. The app does not send it yet; its sales stay bound by items and customer above.
        if ($saleQuoteInput !== null && $this->pricingKey($saleQuoteInput) !== $this->pricingKey((array) $quote->input_payload)) {
            return ['stale_response' => $this->staleQuoteResponse($quote, $userId, $saleItemIds, $saleCustomerId, $saleQuoteInput)];
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

    /** The quote's signed lines and customer are exactly the sale's (order and repeats aside). */
    private function quoteMatchesSale(PosQuote $quote, array $saleItemIds, int $saleCustomerId): bool
    {
        $signed = $quote->breakdown();
        $quoted = collect($signed['lines'] ?? [])->pluck('item_id')->map(fn ($id) => (int) $id);
        $sold = collect($saleItemIds)->map(fn ($id) => (int) $id);

        return (int) ($signed['customer_id'] ?? 0) === $saleCustomerId
            && $quoted->count() === $sold->count()
            && $quoted->sort()->values()->all() === $sold->sort()->values()->all();
    }

    /**
     * A fresh quote with the stale one's discount and offer choices. When the sale's cart differs, it is priced for
     * the sale's own items and customer, so the cashier confirms the bill that will actually be made.
     */
    /**
     * The retail price inputs, in one canonical form: same items in another order, numbers sent as strings, and
     * absent versus null fields compare equal.
     */
    private function pricingKey(array $input): string
    {
        $num = fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2);
        $redemption = $input['scheme_redemption'] ?? null;
        $redemptionAmount = is_array($redemption) ? $num($redemption['amount'] ?? null) : null;

        return json_encode([
            'customer_id' => (int) ($input['customer_id'] ?? 0),
            'item_ids' => collect($input['item_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'manual_discount' => $num($input['manual_discount'] ?? null) ?? 0.0,
            'manual_discount_percent' => $num($input['manual_discount_percent'] ?? null),
            'offer_scheme_id' => isset($input['offer_scheme_id']) && $input['offer_scheme_id'] !== '' ? (int) $input['offer_scheme_id'] : null,
            'scheme_redemption' => $redemptionAmount > 0
                ? ['enrollment_id' => (int) ($redemption['enrollment_id'] ?? 0), 'amount' => $redemptionAmount]
                : null,
        ]);
    }

    protected function staleQuoteResponse(PosQuote $quote, ?int $userId = null, ?array $saleItemIds = null, ?int $saleCustomerId = null, ?array $saleQuoteInput = null): \Illuminate\Http\JsonResponse
    {
        $engine = app(PricingEngine::class);
        $input = $quote->input_payload;
        if ($saleQuoteInput !== null) {
            // Price exactly what the sale states it is paying for; identities stay the server's.
            foreach (['manual_discount', 'manual_discount_percent', 'offer_scheme_id', 'scheme_redemption'] as $key) {
                $input[$key] = $saleQuoteInput[$key] ?? null;
            }
        }
        if ($saleItemIds !== null) {
            $input['item_ids'] = array_values(array_map('intval', $saleItemIds));
            $input['customer_id'] = $saleCustomerId;
        }
        $issued = $engine->quote(
            QuoteInput::fromArray($input),
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
