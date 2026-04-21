<?php

namespace App\Services;

use App\Models\Scheme;
use Illuminate\Support\Collection;

class OfferEngineService
{
    public function getCandidateOffers(int $shopId, bool $autoOnly = false): Collection
    {
        $query = Scheme::query()
            ->where('shop_id', $shopId)
            ->whereIn('type', ['festival_sale', 'discount_offer'])
            ->active()
            ->whereDate('start_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->orderBy('priority')
            ->orderByDesc('discount_value');

        if ($autoOnly) {
            $query->autoApply();
        }

        return $query->get();
    }

    /**
     * @param array<int, array{category?:string, sub_category?:string}> $items
     *
     * @return array<string, mixed>|null
     */
    public function resolveBestOffer(
        int $shopId,
        array $items,
        float $subtotal,
        ?int $selectedSchemeId = null,
        bool $allowAutoFallback = true
    ): ?array {
        if ($subtotal <= 0) {
            return null;
        }

        if ($selectedSchemeId) {
            $selected = Scheme::query()
                ->where('shop_id', $shopId)
                ->whereIn('type', ['festival_sale', 'discount_offer'])
                ->where('id', $selectedSchemeId)
                ->first();

            if (!$selected || !$selected->isRunning()) {
                return null;
            }

            return $this->evaluateScheme($selected, $items, $subtotal, false);
        }

        if (!$allowAutoFallback) {
            return null;
        }

        foreach ($this->getCandidateOffers($shopId, true) as $scheme) {
            $candidate = $this->evaluateScheme($scheme, $items, $subtotal, true);
            if ($candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{category?:string, sub_category?:string}> $items
     *
     * @return array<string, mixed>|null
     */
    public function evaluateScheme(Scheme $scheme, array $items, float $subtotal, bool $autoApplied): ?array
    {
        if (!$scheme->isRunning() || $subtotal <= 0) {
            return null;
        }

        $minPurchase = (float) ($scheme->min_purchase_amount ?? 0);
        if ($minPurchase > 0 && $subtotal < $minPurchase) {
            return null;
        }

        if (!$this->matchesTarget($scheme, $items)) {
            return null;
        }

        $discountType = $scheme->discount_type;
        $discountValue = (float) ($scheme->discount_value ?? 0);

        if (!in_array($discountType, ['percentage', 'flat'], true) || $discountValue <= 0) {
            return null;
        }

        $discountAmount = $discountType === 'percentage'
            ? round($subtotal * ($discountValue / 100), 2)
            : round($discountValue, 2);

        $cap = (float) ($scheme->max_discount_amount ?? 0);
        if ($cap > 0) {
            $discountAmount = min($discountAmount, round($cap, 2));
        }

        $discountAmount = min($discountAmount, round($subtotal, 2));
        if ($discountAmount <= 0) {
            return null;
        }

        return [
            'scheme_id' => $scheme->id,
            'scheme_name' => $scheme->name,
            'scheme_type' => $scheme->type,
            'discount_type' => $discountType,
            'discount_value' => round($discountValue, 2),
            'discount_amount' => round($discountAmount, 2),
            'auto_applied' => $autoApplied,
            'rule_snapshot' => [
                'scheme_id' => $scheme->id,
                'scheme_name' => $scheme->name,
                'scheme_type' => $scheme->type,
                'discount_type' => $discountType,
                'discount_value' => round($discountValue, 2),
                'min_purchase_amount' => round($minPurchase, 2),
                'max_discount_amount' => $cap > 0 ? round($cap, 2) : null,
                'applies_to' => $scheme->applies_to ?? 'all_items',
                'applies_to_value' => $scheme->applies_to_value,
                'evaluated_subtotal' => round($subtotal, 2),
                'evaluated_discount' => round($discountAmount, 2),
            ],
        ];
    }

    /**
     * @param array<int, array{category?:string, sub_category?:string}> $items
     */
    private function matchesTarget(Scheme $scheme, array $items): bool
    {
        $targetType = $scheme->applies_to ?? 'all_items';
        $targetValue = trim((string) ($scheme->applies_to_value ?? ''));

        if ($targetType === 'all_items' || $targetValue === '') {
            return true;
        }

        $needle = mb_strtolower($targetValue);

        if ($targetType === 'category') {
            foreach ($items as $item) {
                $category = mb_strtolower(trim((string) ($item['category'] ?? '')));
                if ($category === $needle) {
                    return true;
                }
            }

            return false;
        }

        if ($targetType === 'sub_category') {
            foreach ($items as $item) {
                $subCategory = mb_strtolower(trim((string) ($item['sub_category'] ?? '')));
                if ($subCategory === $needle) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
