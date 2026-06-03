<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerGoldTransaction;
use App\Models\Item;
use App\Models\PosQuote;
use App\Models\ShopPreferences;
use App\Services\PricingEngine\MakingChargeResolver;
use App\Services\PricingEngine\MakingChargeType;
use App\Services\PricingEngine\PricingBreakdown;
use App\Services\PricingEngine\QuoteInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * The single, authoritative pricing computation for every POS sale.
 *
 * Anyone in the codebase computing money for an invoice MUST route through
 * here — never inline. The engine is deterministic: same QuoteInput, same
 * PricingBreakdown, bit-identical. No `now()`, no `auth()`, no DB writes,
 * no side effects in `compute()`.
 *
 * Numeric contract (matches the `invoices_accounting_guard` DB trigger):
 *   subtotal           = SUM(line.line_total)
 *   taxable            = max(subtotal - total_discount, 0)
 *   gst                = round(taxable * gst_rate / 100, 2)
 *   pre_round_total    = round(subtotal + gst + wastage_charge - total_discount, 2)
 *   final_total        = pre_round_total + rounding_adjustment
 *
 * Rounding is shop-configurable (Tally-style):
 *   - method ∈ {none, normal, upward, downward}
 *   - nearest ∈ {0.01, 0.10, 1, 5, 10}
 * The cashier never picks rounding — it's a shop preference.
 */
class PricingEngine
{
    public const QUOTE_TTL_RETAILER_MIN     = 60;
    public const QUOTE_TTL_MANUFACTURER_MIN = 30;

    public function __construct(
        private readonly OfferEngineService $offerEngine,
        private readonly ComplianceService $complianceService,
    ) {
    }

    /**
     * Whether the v2 signed-quote POS flow is active for the given shop.
     *
     * The global flag (config('features.pos_quote_v2')) is the master switch
     * during rollout. The /pos/quote + /pos/quote/persist endpoints function
     * regardless — they're idempotent reads/inserts that don't mutate sale
     * state — so we can dogfood without exposing the flag to the public.
     * Frontends gate their wiring on the value returned here (echoed back as
     * `feature_enabled` in the /pos/quote response).
     *
     * Future: per-shop opt-in via ShopPreferences.pos_quote_v2_enabled column.
     */
    public static function isQuoteFlowEnabled(int $shopId): bool
    {
        if (! config('features.pos_quote_v2', false)) {
            return false;
        }
        // (future hook: read per-shop preference here for canary rollout)
        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    //  Public entry points
    // ────────────────────────────────────────────────────────────────────

    /**
     * Compute the pricing breakdown for the given input. Pure function.
     */
    public function compute(QuoteInput $input): PricingBreakdown
    {
        return $input->mode === QuoteInput::MODE_RETAILER
            ? $this->computeRetailer($input)
            : $this->computeManufacturer($input);
    }

    /**
     * Re-compute the breakdown that was stamped into a stored quote, using
     * current item prices / offer state. Caller compares the recomputed
     * canonical JSON against `quote->breakdown_json` to detect drift.
     *
     * The original quote's identity (quote_id + expires_at) is re-stamped so
     * the recomputed canonical JSON is byte-comparable to the stored bytes —
     * any divergence proves a real pricing drift, not an identity mismatch.
     */
    public function recompute(PosQuote $quote): PricingBreakdown
    {
        $input     = QuoteInput::fromArray($quote->input_payload);
        $breakdown = $this->compute($input);
        $expiresAt = $quote->expires_at instanceof \DateTimeInterface
            ? $quote->expires_at->format(\DateTimeInterface::ATOM)
            : (string) $quote->expires_at;
        return $this->withIdentity($breakdown, $quote->quote_id, $expiresAt);
    }

    /**
     * Repair-invoice flow: amount is typed by the staff (no items, no offers).
     * Engine still honours the shop's rounding strategy so the printed invoice
     * total matches what the cashier collects.
     */
    public function computeRepair(int $shopId, float $amount, bool $includeGst, ?float $gstRate = null): PricingBreakdown
    {
        $prefs    = $this->loadPreferences($shopId);
        $rate     = $includeGst ? (float) ($gstRate ?? (config('business.gst_rate_default') ?? 0)) : 0.0;
        $subtotal = round((float) $amount, 2);
        $gst      = $includeGst ? round($subtotal * ($rate / 100), 2) : 0.0;

        $preRound  = round($subtotal + $gst, 2);
        [$method, $nearest, $adjustment] = $this->applyRounding($preRound, $prefs);

        return new PricingBreakdown(
            shopId: $shopId,
            customerId: null,
            mode: PosQuote::MODE_REPAIR,
            lines: [[
                'item_id'    => null,
                'line_total' => $subtotal,
                'gst_amount' => $gst,
            ]],
            subtotal: $subtotal,
            manualDiscount: 0.0,
            offerDiscount: 0.0,
            totalDiscount: 0.0,
            taxable: $subtotal,
            gstRate: $rate,
            gst: $gst,
            wastageCharge: 0.0,
            preRoundTotal: $preRound,
            roundingAdjustment: $adjustment,
            finalTotal: round($preRound + $adjustment, 2),
            roundingMethod: $method,
            roundingNearest: $nearest,
        );
    }

    /**
     * Exchange-invoice flow: produces the same persisted numbers as today's
     * SalesService::sellItem path. creditOffset is recorded in input_payload
     * for audit but does NOT reduce the persisted subtotal/gst/total — the
     * credit is settled separately via cash/buyback ledger entries (preserves
     * pre-existing exchange behaviour).
     */
    public function computeForExchange(
        int $shopId,
        ?int $customerId,
        int $itemId,
        float $goldRate,
        float $making,
        float $stone,
        float $creditOffset = 0.0,
    ): PricingBreakdown {
        $input = QuoteInput::manufacturer(
            shopId: $shopId,
            customerId: $customerId,
            itemId: $itemId,
            goldRate: $goldRate,
            making: $making,
            stone: $stone,
            manualDiscount: 0.0,
            creditOffset: $creditOffset,
        );
        return $this->computeManufacturer($input);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Signing / verification (HMAC-SHA256 over canonical JSON)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Returns [breakdown_json, signature, breakdown_hash]. The JSON bytes
     * stored in pos_quotes.breakdown_json MUST be exactly this string — never
     * re-serialise on verify.
     */
    public function sign(PricingBreakdown $breakdown): array
    {
        $json      = $breakdown->toCanonicalJson();
        $hash      = hash('sha256', $json);
        $signature = hash_hmac('sha256', $json, $this->signingKey());

        return [$json, $signature, $hash];
    }

    /**
     * Constant-time verification. Returns true iff `signature` is the correct
     * HMAC of the stored breakdown JSON under the current APP_KEY.
     */
    public function verify(string $breakdownJson, string $signature): bool
    {
        $expected = hash_hmac('sha256', $breakdownJson, $this->signingKey());
        return hash_equals($expected, $signature);
    }

    /**
     * Issue a new quote: compute, sign, and stamp identity fields. Caller
     * may persist the result via PricingEngine::persist().
     */
    public function quote(QuoteInput $input, ?int $userId = null): array
    {
        $breakdown = $this->compute($input);
        $quoteId   = (string) Str::ulid();
        $ttlMin    = $input->mode === QuoteInput::MODE_MANUFACTURER
            ? self::QUOTE_TTL_MANUFACTURER_MIN
            : self::QUOTE_TTL_RETAILER_MIN;
        $expiresAt = now()->addMinutes($ttlMin);

        // Stamp quote_id + expires_at into the breakdown so they're inside
        // the canonical JSON that gets signed (clients can't fake them).
        $breakdownWithIdentity = $this->withIdentity($breakdown, $quoteId, $expiresAt->toIso8601String());
        [$json, $signature, $hash] = $this->sign($breakdownWithIdentity);

        return [
            'breakdown'      => $breakdownWithIdentity,
            'quote_id'       => $quoteId,
            'expires_at'     => $expiresAt,
            'breakdown_json' => $json,
            'signature'      => $signature,
            'breakdown_hash' => $hash,
            'input'          => $input,
            'user_id'        => $userId,
        ];
    }

    /**
     * Persist a quote produced by ::quote(). Idempotent: returns the existing
     * row if `idempotency_key` collides on (shop_id, idempotency_key).
     */
    public function persist(array $issued, ?string $idempotencyKey = null, string $client = 'web'): PosQuote
    {
        $input = $issued['input'];
        assert($input instanceof QuoteInput);

        if ($idempotencyKey !== null) {
            $existing = PosQuote::where('shop_id', $input->shopId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return PosQuote::create([
            'quote_id'           => $issued['quote_id'],
            'shop_id'            => $input->shopId,
            'customer_id'        => $input->customerId,
            'created_by_user_id' => $issued['user_id'],
            'mode'               => $input->mode,
            'client'             => $client,
            'input_payload'      => $input->toArray(),
            'breakdown_json'     => $issued['breakdown_json'],
            'breakdown_hash'     => $issued['breakdown_hash'],
            'signature'          => $issued['signature'],
            'expires_at'         => $issued['expires_at'],
            'idempotency_key'    => $idempotencyKey,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Retailer mode
    // ────────────────────────────────────────────────────────────────────

    private function computeRetailer(QuoteInput $input): PricingBreakdown
    {
        $items = $this->loadItems($input->shopId, $input->itemIds);
        $prefs = $this->loadPreferences($input->shopId);
        $shop  = $this->loadShop($input->shopId);
        $gstRate = (float) ($shop->gst_rate ?? config('business.gst_rate_default') ?? 0);

        $subtotal = round((float) $items->sum('selling_price'), 2);

        // Manual discount may be capped by ShopPreferences.max_manual_discount_percent.
        $manualDiscount = $this->clampManualDiscount(
            $input->manualDiscount,
            $input->manualDiscountPercent,
            $subtotal,
            $prefs,
        );

        // Offer discount via the existing offer engine (already pure).
        $offerPayload = $this->offerEngine->resolveBestOffer(
            $input->shopId,
            $items->map(fn (Item $item) => [
                'category'     => $item->category,
                'sub_category' => $item->sub_category,
            ])->all(),
            $subtotal,
            $input->offerSchemeId,
            $input->allowAutoOfferFallback,
        );
        $offerDiscount = (float) ($offerPayload['discount_amount'] ?? 0);
        $totalDiscount = min(round($subtotal, 2), round($manualDiscount + $offerDiscount, 2));

        $taxable = max(round($subtotal - $totalDiscount, 2), 0.0);
        $gst     = round($taxable * ($gstRate / 100), 2);

        // Per-line GST apportionment via largest-remainder (matches what
        // RetailerSalesService stamps onto each invoice_items row).
        $lineTotals = [];
        foreach ($items as $item) {
            $lineTotals[$item->id] = (float) $item->selling_price;
        }
        $apportioned = InvoiceAccountingService::apportionGstToLines($lineTotals, $gst);

        $preRound = round($subtotal + $gst - $totalDiscount, 2);
        [$method, $nearest, $adjustment] = $this->applyRounding($preRound, $prefs);
        $finalTotal = round($preRound + $adjustment, 2);

        $compliance = $this->complianceFor($input->shopId, $input->customerId, $finalTotal);

        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'item_id'    => (int) $item->id,
                'line_total' => (float) $item->selling_price,
                'gst_amount' => (float) ($apportioned[$item->id] ?? 0.0),
                'weight'     => (float) ($item->net_metal_weight ?? 0),
                'rate'       => (float) $item->selling_price,
                'making'     => (float) ($item->making_charges ?? 0),
                'stone'      => (float) ($item->stone_charges ?? 0),
            ];
        }

        return new PricingBreakdown(
            shopId: $input->shopId,
            customerId: $input->customerId,
            mode: QuoteInput::MODE_RETAILER,
            lines: $lines,
            subtotal: $subtotal,
            manualDiscount: round($manualDiscount, 2),
            offerDiscount: round($offerDiscount, 2),
            totalDiscount: round($totalDiscount, 2),
            taxable: $taxable,
            gstRate: $gstRate,
            gst: $gst,
            wastageCharge: 0.0,
            preRoundTotal: $preRound,
            roundingAdjustment: $adjustment,
            finalTotal: $finalTotal,
            roundingMethod: $method,
            roundingNearest: $nearest,
            offer: $offerPayload,
            compliance: $compliance,
        );
    }

    // ────────────────────────────────────────────────────────────────────
    //  Manufacturer mode
    // ────────────────────────────────────────────────────────────────────

    private function computeManufacturer(QuoteInput $input): PricingBreakdown
    {
        $items = $this->loadItems($input->shopId, $input->itemIds);
        $prefs = $this->loadPreferences($input->shopId);
        $shop  = $this->loadShop($input->shopId);
        $gstRate = (float) ($shop->gst_rate ?? config('business.gst_rate_default') ?? 0);

        /** @var Item $item */
        $item = $items->first();
        if (! $item) {
            throw new LogicException('Manufacturer pricing requires an item.');
        }

        $goldRate = (float) ($input->goldRate ?? 0);
        $stone    = (float) $input->stone;

        $fineMultiplier = \App\Services\MetalRegistry::fineWeightMultiplier((string) $item->metal_type, (float) $item->purity);
        if ($fineMultiplier === null) {
            throw new \LogicException("Cannot derive fine weight for non-accounting metal '{$item->metal_type}'.");
        }
        $fineGold = (float) $item->net_metal_weight * $fineMultiplier;
        $metalValue = round($fineGold * $goldRate, 2);

        // MC-2: resolve making via the one canonical helper. For FIXED mode the
        // value mirrors $input->making → resolved == $input->making (byte-identical).
        $making = MakingChargeResolver::resolve(
            $input->makingType,
            $input->makingValue ?? (float) $input->making,
            $metalValue,
            (float) $item->net_metal_weight,
        );

        $lineTotal  = round($metalValue + $making + $stone, 2);

        // Manufacturer per-shop wastage recovery (matches SalesService line 75-78).
        $wastageRecoveryPercent = (float) ($shop->wastage_recovery_percent ?? 100);
        $wastageFineGold        = (float) ($item->wastage ?? 0);
        $wastageCharge          = round(($wastageFineGold * $goldRate * $wastageRecoveryPercent) / 100, 2);

        $manualDiscount = $this->clampManualDiscount(
            $input->manualDiscount,
            $input->manualDiscountPercent,
            $lineTotal,
            $prefs,
        );
        $totalDiscount = round($manualDiscount, 2);

        $taxable = max(round($lineTotal - $totalDiscount, 2), 0.0);
        $gst     = round($taxable * ($gstRate / 100), 2);

        // Single-line manufacturer invoice: all GST goes onto the one line.
        $apportioned = InvoiceAccountingService::apportionGstToLines(
            [$item->id => $lineTotal],
            $gst,
        );

        $preRound = round($lineTotal + $gst + $wastageCharge - $totalDiscount, 2);
        [$method, $nearest, $adjustment] = $this->applyRounding($preRound, $prefs);
        $finalTotal = round($preRound + $adjustment, 2);

        $customerGold = $this->customerGoldBalance($input->shopId, $input->customerId, $fineGold);
        $compliance   = $this->complianceFor($input->shopId, $input->customerId, $finalTotal);

        $line = [
            'item_id'    => (int) $item->id,
            'line_total' => $lineTotal,
            'gst_amount' => (float) ($apportioned[$item->id] ?? 0.0),
            'weight'     => (float) $item->net_metal_weight,
            'rate'       => $goldRate,
            'making'     => $making,
            'stone'      => $stone,
        ];
        // MC-2: append mode metadata ONLY for non-fixed modes → fixed-mode
        // canonical bytes are unchanged and historical signed quotes verify.
        if ($input->makingType !== MakingChargeType::FIXED) {
            $line['making_type']  = $input->makingType;
            $line['making_value'] = (float) $input->makingValue;
        }
        $lines = [$line];

        return new PricingBreakdown(
            shopId: $input->shopId,
            customerId: $input->customerId,
            mode: QuoteInput::MODE_MANUFACTURER,
            lines: $lines,
            subtotal: $lineTotal,
            manualDiscount: round($manualDiscount, 2),
            offerDiscount: 0.0,
            totalDiscount: $totalDiscount,
            taxable: $taxable,
            gstRate: $gstRate,
            gst: $gst,
            wastageCharge: $wastageCharge,
            preRoundTotal: $preRound,
            roundingAdjustment: $adjustment,
            finalTotal: $finalTotal,
            roundingMethod: $method,
            roundingNearest: $nearest,
            customerGold: $customerGold,
            compliance: $compliance,
        );
    }

    // ────────────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Apply the shop's rounding strategy to the pre-round total.
     * Returns [method, nearest, adjustment]. adjustment is (rounded - preRound)
     * — what gets stored as invoice.round_off.
     */
    private function applyRounding(float $preRound, ?ShopPreferences $prefs): array
    {
        $method  = (string) ($prefs->rounding_method ?? 'none');
        $nearest = (float) ($prefs->round_off_nearest ?? 1);

        if ($method === 'none' || $nearest <= 0) {
            return ['none', $nearest, 0.0];
        }

        $units = $preRound / $nearest;
        $rounded = match ($method) {
            'upward'   => ceil($units) * $nearest,
            'downward' => floor($units) * $nearest,
            'normal'   => round($units, 0, PHP_ROUND_HALF_UP) * $nearest,
            default    => $preRound,
        };
        $rounded = round($rounded, 4);
        $adjustment = round($rounded - $preRound, 4);

        return [$method, $nearest, $adjustment];
    }

    /**
     * Cap manual discount by the shop's max_manual_discount_percent if set.
     * Returns the resolved discount in rupees (not percent).
     */
    private function clampManualDiscount(
        float $discountAmount,
        ?float $discountPercent,
        float $base,
        ?ShopPreferences $prefs,
    ): float {
        $resolved = $discountAmount;
        if ($discountPercent !== null && $discountPercent > 0 && $base > 0) {
            $resolved = max($resolved, round($base * ($discountPercent / 100), 2));
        }
        $resolved = min(round($resolved, 2), round($base, 2));

        $cap = (float) ($prefs->max_manual_discount_percent ?? 0);
        if ($cap > 0 && $base > 0) {
            $capValue = round($base * ($cap / 100), 2);
            $resolved = min($resolved, $capValue);
        }

        return max(0.0, $resolved);
    }

    private function customerGoldBalance(int $shopId, ?int $customerId, float $requiredFine): ?array
    {
        if (! $customerId) {
            return null;
        }
        $balance = (float) CustomerGoldTransaction::where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->sum('fine_gold');
        $used    = min($balance, $requiredFine);
        return [
            'fine_required' => round($requiredFine, 3),
            'fine_balance'  => round($balance, 3),
            'fine_usable'   => round($used, 3),
            'fine_charged'  => round(max(0.0, $requiredFine - $used), 3),
        ];
    }

    private function complianceFor(int $shopId, ?int $customerId, float $finalTotal): ?array
    {
        if (! $customerId) {
            return null;
        }
        $missing = $this->complianceService->checkRequired($shopId, $customerId, $finalTotal);
        if ($missing === null) {
            return ['required' => false, 'missing_fields' => [], 'threshold' => null];
        }

        $prefs = $this->loadPreferences($shopId);
        return [
            'required'       => ! empty($missing),
            'missing_fields' => array_values($missing),
            'threshold'      => (float) ($prefs->compliance_threshold ?? 200000),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Item> */
    private function loadItems(int $shopId, array $itemIds)
    {
        if (empty($itemIds)) {
            return Item::query()->whereRaw('1 = 0')->get();
        }
        return Item::where('shop_id', $shopId)
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id')
            ->only($itemIds)
            ->values();
    }

    private function loadPreferences(int $shopId): ?ShopPreferences
    {
        return ShopPreferences::where('shop_id', $shopId)->first();
    }

    private function loadShop(int $shopId): ?\App\Models\Shop
    {
        return \App\Models\Shop::find($shopId);
    }

    private function withIdentity(PricingBreakdown $breakdown, string $quoteId, string $expiresAt): PricingBreakdown
    {
        return new PricingBreakdown(
            shopId: $breakdown->shopId,
            customerId: $breakdown->customerId,
            mode: $breakdown->mode,
            lines: $breakdown->lines,
            subtotal: $breakdown->subtotal,
            manualDiscount: $breakdown->manualDiscount,
            offerDiscount: $breakdown->offerDiscount,
            totalDiscount: $breakdown->totalDiscount,
            taxable: $breakdown->taxable,
            gstRate: $breakdown->gstRate,
            gst: $breakdown->gst,
            wastageCharge: $breakdown->wastageCharge,
            preRoundTotal: $breakdown->preRoundTotal,
            roundingAdjustment: $breakdown->roundingAdjustment,
            finalTotal: $breakdown->finalTotal,
            roundingMethod: $breakdown->roundingMethod,
            roundingNearest: $breakdown->roundingNearest,
            offer: $breakdown->offer,
            compliance: $breakdown->compliance,
            customerGold: $breakdown->customerGold,
            quoteId: $quoteId,
            expiresAt: $expiresAt,
        );
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        // Strip Laravel's "base64:" prefix if present so the HMAC uses raw bytes.
        return str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true) ?: $key
            : $key;
    }
}
