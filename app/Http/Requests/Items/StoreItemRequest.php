<?php

namespace App\Http\Requests\Items;

use App\Rules\Inventory\UniqueBarcodeForShop;
use App\Rules\Material\IsEnabledMetal;
use App\Rules\Material\PurityRequiredForAccountingTruth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Canonical retailer item-creation validation. Web and mobile both extend
 * this so the three previously-duplicated rules — enabled-metal,
 * purity-required-for-accounting-truth, barcode-uniqueness-per-shop — have
 * a single authority.
 *
 * Authorization here is permissive (return true); the actual gate is the
 * `can:inventory.create` middleware on the route.
 *
 * NOTE: Update flows are not covered yet. UpdateItemRequest variants are a
 * follow-up TODO once shared semantics are confirmed via the parity test.
 */
abstract class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shopId = (int) ($this->user()?->shop_id ?? 0);

        return [
            'barcode' => [
                'required', 'string', 'max:100',
                new UniqueBarcodeForShop($shopId),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'sub_category' => 'nullable|string|max:255',
            'metal_type' => [
                'required', 'string',
                new IsEnabledMetal($shopId),
            ],
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => [
                'nullable', 'numeric', 'min:0.001', 'max:1000',
                new PurityRequiredForAccountingTruth(fn () => $this->input('metal_type')),
            ],
            'cost_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'hallmark_charges' => 'nullable|numeric|min:0',
            'rhodium_charges' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'karigar_id' => ['nullable', Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
            'huid' => [
                'nullable', 'string', 'max:30',
                Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid'),
            ],
            'hallmark_date' => 'nullable|date',
        ];
    }
}
