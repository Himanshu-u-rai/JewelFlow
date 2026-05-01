<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Item;
use App\Models\Karigar;
use App\Models\Shop;
use App\Models\Vendor;
use App\Services\CatalogShareService;
use App\Services\PosSearchCacheService;
use App\Services\ShopPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class ItemController extends Controller
{
    public function __construct(
        private CatalogShareService $catalog,
        private ShopPricingService $pricing
    ) {}

    public function search(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $results = PosSearchCacheService::items($shopId, $request->input('search'));

        return response()->json($results);
    }

    public function findByBarcode(string $barcode, Request $request): JsonResponse
    {
        $item = Item::query()
            ->where('shop_id', (int) $request->user()->shop_id)
            ->where('barcode', $barcode)
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        return response()->json($this->itemPayload($request, $item));
    }

    public function show(Item $item, Request $request): JsonResponse
    {
        if ((int) $item->shop_id !== (int) $request->user()->shop_id) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        return response()->json($this->itemPayload($request, $item));
    }

    public function update(Item $item, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $item->shop_id !== $shopId) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        if ($item->status !== 'in_stock') {
            return response()->json([
                'message' => 'Only in-stock items can be edited.',
            ], 422);
        }

        $shop = $request->user()->shop;
        $isRetailer = $shop && $shop->isRetailer();

        $rules = [
            'barcode' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('items', 'barcode')->where('shop_id', $shopId)->ignore($item->id),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'sub_category' => 'nullable|string|max:255',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'image_base64' => 'nullable|string',
            'remove_image' => 'nullable|boolean',
        ];

        if ($isRetailer) {
            try {
                $this->pricing->assertRetailerPricingReady($shop);
            } catch (LogicException $e) {
                return $this->retailerPricingBlockedResponse($e->getMessage());
            }

            $rules = array_merge($rules, [
                'gross_weight' => 'sometimes|required|numeric|min:0.001',
                'stone_weight' => 'nullable|numeric|min:0',
                'metal_type' => ['sometimes', 'required', Rule::in(['gold', 'silver'])],
                'purity' => 'sometimes|required|numeric|min:0.001|max:1000',
                'cost_price' => 'sometimes|nullable|numeric|min:0',
                'selling_price' => 'sometimes|nullable|numeric|min:0',
                'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
                'karigar_id' => ['nullable', Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
                'huid' => [
                    'nullable', 'string', 'max:30',
                    Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid')->ignore($item->id),
                ],
                'hallmark_date' => 'nullable|date',
                'hallmark_charges' => 'nullable|numeric|min:0',
                'rhodium_charges' => 'nullable|numeric|min:0',
                'other_charges' => 'nullable|numeric|min:0',
            ]);
        }

        $validated = $request->validate($rules);
        if (
            array_key_exists('vendor_id', $validated)
            && array_key_exists('karigar_id', $validated)
            && $validated['vendor_id'] !== null
            && $validated['karigar_id'] !== null
        ) {
            return response()->json([
                'message' => 'Select either a vendor or a karigar, not both.',
            ], 422);
        }

        $retailerPricing = null;

        if ($isRetailer) {
            $pricingAttributes = [
                'metal_type' => $validated['metal_type'] ?? $item->metal_type,
                'purity' => $validated['purity'] ?? $item->purity,
                'gross_weight' => $validated['gross_weight'] ?? $item->gross_weight,
                'stone_weight' => array_key_exists('stone_weight', $validated) ? $validated['stone_weight'] : $item->stone_weight,
                'making_charges' => array_key_exists('making_charges', $validated) ? $validated['making_charges'] : $item->making_charges,
                'stone_charges' => array_key_exists('stone_charges', $validated) ? $validated['stone_charges'] : $item->stone_charges,
                'hallmark_charges' => array_key_exists('hallmark_charges', $validated) ? $validated['hallmark_charges'] : $item->hallmark_charges,
                'rhodium_charges' => array_key_exists('rhodium_charges', $validated) ? $validated['rhodium_charges'] : $item->rhodium_charges,
                'other_charges' => array_key_exists('other_charges', $validated) ? $validated['other_charges'] : $item->other_charges,
            ];

            try {
                $retailerPricing = $this->pricing->computeRetailerCostPayload($shop, $pricingAttributes);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $newImagePath = null;
        $clearImage = false;

        if (! empty($validated['image_base64'])) {
            $imageData = base64_decode($validated['image_base64'], true);
            if ($imageData === false) {
                return response()->json(['message' => 'Invalid image data.'], 422);
            }
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($imageData);
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (! isset($allowedMimes[$mime])) {
                return response()->json([
                    'message' => 'Invalid image format. Allowed: JPEG, PNG, WebP.',
                ], 422);
            }
            $newImagePath = 'items/' . Str::ulid() . '.' . $allowedMimes[$mime];
            Storage::disk('public')->put($newImagePath, $imageData);
        } elseif (! empty($validated['remove_image'])) {
            $clearImage = true;
        }

        // Drop the transport-only fields so they don't leak into the model update.
        unset($validated['image_base64'], $validated['remove_image']);

        DB::transaction(function () use ($item, $validated, $isRetailer, $retailerPricing, $newImagePath, $clearImage) {
            $previousImage = $item->image;
            $updates = array_filter([
                'barcode' => $validated['barcode'] ?? null,
                'design' => array_key_exists('design', $validated) ? $validated['design'] : null,
                'category' => $validated['category'] ?? null,
                'sub_category' => array_key_exists('sub_category', $validated) ? $validated['sub_category'] : null,
                'making_charges' => $validated['making_charges'] ?? null,
                'stone_charges' => $validated['stone_charges'] ?? null,
            ], fn ($v) => $v !== null);

            // Allow clearing nullable text fields via explicit null values.
            foreach (['design', 'sub_category'] as $nullable) {
                if (array_key_exists($nullable, $validated)) {
                    $updates[$nullable] = $validated[$nullable];
                }
            }

            if ($isRetailer) {
                if ($retailerPricing) {
                    $updates['metal_type'] = $retailerPricing['metal_type'];
                    $updates['gross_weight'] = $validated['gross_weight'] ?? $item->gross_weight;
                    $updates['stone_weight'] = array_key_exists('stone_weight', $validated) ? $validated['stone_weight'] : $item->stone_weight;
                    $updates['net_metal_weight'] = $retailerPricing['net_metal_weight'];
                    $updates['purity'] = $retailerPricing['purity'];
                    $updates['cost_price'] = $retailerPricing['cost_price'];
                    $updates['selling_price'] = $retailerPricing['selling_price'];
                    $updates['hallmark_charges'] = array_key_exists('hallmark_charges', $validated) ? $validated['hallmark_charges'] : $item->hallmark_charges;
                    $updates['rhodium_charges'] = array_key_exists('rhodium_charges', $validated) ? $validated['rhodium_charges'] : $item->rhodium_charges;
                    $updates['other_charges'] = array_key_exists('other_charges', $validated) ? $validated['other_charges'] : $item->other_charges;
                    $updates['pricing_review_required'] = false;
                    $updates['pricing_review_notes'] = null;
                }

                foreach (['vendor_id', 'karigar_id', 'huid', 'hallmark_date'] as $nullable) {
                    if (array_key_exists($nullable, $validated)) {
                        $updates[$nullable] = $validated[$nullable];
                    }
                }
            }

            if ($newImagePath !== null) {
                $updates['image'] = $newImagePath;
            } elseif ($clearImage) {
                $updates['image'] = null;
            }

            if (! empty($updates)) {
                $item->update($updates);
            }

            // Clean up the replaced image from disk AFTER the row update commits,
            // so a failed update never orphans the previous file.
            if (
                ($newImagePath !== null || $clearImage)
                && $previousImage
                && $previousImage !== $newImagePath
                && Storage::disk('public')->exists($previousImage)
            ) {
                Storage::disk('public')->delete($previousImage);
            }

            AuditLog::create([
                'shop_id' => $item->shop_id,
                'user_id' => auth()->id(),
                'action' => 'item_updated',
                'model_type' => 'item',
                'model_id' => $item->id,
                'data' => array_merge(['source' => 'mobile_app'], $validated, [
                    'image_changed' => $newImagePath !== null || $clearImage,
                    'client_cost_price_ignored' => $isRetailer && array_key_exists('cost_price', $validated),
                    'client_selling_price_ignored' => $isRetailer && array_key_exists('selling_price', $validated),
                ]),
            ]);
        });

        $item->refresh();

        return response()->json($this->itemPayload($request, $item));
    }

    private function itemPayload(Request $request, Item $item): array
    {
        $token = $this->catalog->ensureItemHasToken($item);
        $shop = $request->user()->shop;
        if ($shop) {
            $shop->loadMissing('catalogWebsiteSettings');
        }
        $resolvedRatePerGram = $this->resolvedRatePerGramForItem($shop, $item);

        return [
            'id' => $item->id,
            'barcode' => $item->barcode,
            'design' => $item->design,
            'category' => $item->category,
            'sub_category' => $item->sub_category,
            'metal_type' => $item->metal_type,
            'gross_weight' => (float) $item->gross_weight,
            'stone_weight' => (float) $item->stone_weight,
            'net_metal_weight' => (float) $item->net_metal_weight,
            'purity' => (float) $item->purity,
            'purity_label' => $item->purity_label,
            'cost_price' => (float) $item->cost_price,
            'selling_price' => (float) $item->selling_price,
            'making_charges' => (float) $item->making_charges,
            'stone_charges' => (float) $item->stone_charges,
            'hallmark_charges' => (float) $item->hallmark_charges,
            'rhodium_charges' => (float) $item->rhodium_charges,
            'other_charges' => (float) $item->other_charges,
            'resolved_rate_per_gram' => $resolvedRatePerGram,
            'status' => $item->status,
            'huid' => $item->huid,
            'hallmark_date' => $item->hallmark_date?->toDateString(),
            'image' => $this->catalog->resolveImageUrl($request, $item),
            'share_url' => $this->buildProductShareUrl($shop, $token),
            'vendor_id' => $item->vendor_id,
            'karigar_id' => $item->karigar_id,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;
        $shop = $request->user()->shop;
        $isRetailer = $shop && $shop->isRetailer();

        if ($isRetailer) {
            try {
                $this->pricing->assertRetailerPricingReady($shop);
            } catch (LogicException $e) {
                return $this->retailerPricingBlockedResponse($e->getMessage());
            }
        }

        $rules = [
            'barcode' => [
                'required', 'string', 'max:100',
                Rule::unique('items', 'barcode')->where('shop_id', $shopId),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => $isRetailer ? 'required|numeric|min:0.001|max:1000' : 'required|numeric|min:1|max:24',
            'cost_price' => $isRetailer ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
            'selling_price' => $isRetailer ? 'nullable|numeric|min:0' : 'required|numeric|min:0',
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
            'image_base64' => 'nullable|string',
        ];

        if ($isRetailer) {
            $rules['metal_type'] = ['required', Rule::in(['gold', 'silver'])];
        }

        $validated = $request->validate($rules);
        if (
            array_key_exists('vendor_id', $validated)
            && array_key_exists('karigar_id', $validated)
            && $validated['vendor_id'] !== null
            && $validated['karigar_id'] !== null
        ) {
            return response()->json([
                'message' => 'Select either a vendor or a karigar, not both.',
            ], 422);
        }

        $imagePath = null;
        if (! empty($validated['image_base64'])) {
            $imageData = base64_decode($validated['image_base64']);
            if ($imageData !== false) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($imageData);
                $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

                if (! isset($allowedMimes[$mime])) {
                    return response()->json(['message' => 'Invalid image format. Allowed: JPEG, PNG, WebP.'], 422);
                }

                $filename = 'items/' . Str::ulid() . '.' . $allowedMimes[$mime];
                Storage::disk('public')->put($filename, $imageData);
                $imagePath = $filename;
            }
        }

        $pricingPayload = null;
        if ($isRetailer) {
            try {
                $pricingPayload = $this->pricing->computeRetailerCostPayload($shop, $validated);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $netMetalWeight = $pricingPayload['net_metal_weight'] ?? ($validated['gross_weight'] - ($validated['stone_weight'] ?? 0));

        $item = DB::transaction(function () use ($shopId, $validated, $isRetailer, $pricingPayload, $netMetalWeight, $imagePath) {
            $item = Item::create([
                'shop_id' => $shopId,
                'barcode' => $validated['barcode'],
                'design' => $validated['design'] ?? null,
                'category' => $validated['category'],
                'sub_category' => $validated['sub_category'] ?? null,
                'metal_type' => $pricingPayload['metal_type'] ?? null,
                'gross_weight' => $validated['gross_weight'],
                'stone_weight' => $validated['stone_weight'] ?? 0,
                'net_metal_weight' => $netMetalWeight,
                'purity' => $pricingPayload['purity'] ?? $validated['purity'],
                'making_charges' => $validated['making_charges'] ?? 0,
                'stone_charges' => $validated['stone_charges'] ?? 0,
                'hallmark_charges' => $validated['hallmark_charges'] ?? 0,
                'rhodium_charges' => $validated['rhodium_charges'] ?? 0,
                'other_charges' => $validated['other_charges'] ?? 0,
                'cost_price' => $isRetailer
                    ? $pricingPayload['cost_price']
                    : $validated['cost_price'],
                'selling_price' => $isRetailer
                    ? $pricingPayload['selling_price']
                    : $validated['selling_price'],
                'vendor_id' => $validated['vendor_id'] ?? null,
                'karigar_id' => $validated['karigar_id'] ?? null,
                'huid' => $validated['huid'] ?? null,
                'hallmark_date' => $validated['hallmark_date'] ?? null,
                'source' => 'purchased',
                'status' => 'in_stock',
                'image' => $imagePath,
                'pricing_review_required' => false,
                'pricing_review_notes' => null,
            ]);

            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => auth()->id(),
                'action' => 'item_created',
                'model_type' => 'item',
                'model_id' => $item->id,
                'data' => [
                    'barcode' => $item->barcode,
                    'category' => $item->category,
                    'selling_price' => $item->selling_price,
                    'source' => 'mobile_app',
                    'client_cost_price_ignored' => $isRetailer && array_key_exists('cost_price', $validated),
                    'client_selling_price_ignored' => $isRetailer && array_key_exists('selling_price', $validated),
                ],
            ]);

            return $item;
        });

        return response()->json(array_merge(
            $this->itemPayload($request, $item->fresh()),
            ['message' => 'Item registered successfully.']
        ), 201);
    }

    public function retailerPricingMeta(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;

        if (! $shop || ! $shop->isRetailer()) {
            return response()->json([
                'message' => 'Retailer pricing metadata is only available for retailer shops.',
            ], 404);
        }

        try {
            $this->pricing->assertRetailerPricingReady($shop);
        } catch (LogicException $e) {
            return $this->retailerPricingBlockedResponse($e->getMessage());
        }

        $purityProfiles = $this->pricing->activePurityProfiles($shop)->groupBy('metal_type');

        return response()->json([
            'business_date' => $this->pricing->businessDateString($shop),
            'purity_profiles' => [
                'gold' => $this->purityProfileOptions($purityProfiles->get('gold', collect())),
                'silver' => $this->purityProfileOptions($purityProfiles->get('silver', collect())),
            ],
            'resolved_rates' => $this->buildRetailerResolvedRateMap($shop, $purityProfiles),
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = Category::select('id', 'name', 'slug')->orderBy('name')->get();

        return response()->json($categories);
    }

    public function vendors(Request $request): JsonResponse
    {
        $vendors = Vendor::active()
            ->select('id', 'name', 'mobile')
            ->orderBy('name')
            ->get();

        return response()->json($vendors);
    }

    public function karigars(Request $request): JsonResponse
    {
        $karigars = Karigar::active()
            ->select('id', 'name', 'mobile')
            ->orderBy('name')
            ->get();

        return response()->json($karigars);
    }

    private function buildProductShareUrl(?Shop $shop, string $token): string
    {
        return $this->catalog->buildPreferredProductUrl($shop, $token);
    }

    private function resolvedRatePerGramForItem(?Shop $shop, Item $item): ?float
    {
        if (! $shop || ! $shop->isRetailer() || ! is_string($item->metal_type) || $item->metal_type === '' || $item->purity === null) {
            return null;
        }

        try {
            return $this->pricing->resolvedRateForToday($shop, $item->metal_type, (float) $item->purity);
        } catch (\Throwable) {
            return null;
        }
    }

    private function purityProfileOptions(Collection $profiles): array
    {
        return $profiles
            ->map(fn ($profile) => [
                'value' => $this->pricing->normalizePurityString((float) $profile->purity_value),
                'label' => (string) $profile->label,
            ])
            ->values()
            ->all();
    }

    private function buildRetailerResolvedRateMap(Shop $shop, Collection $purityProfiles): array
    {
        $resolvedRates = [
            'gold' => [],
            'silver' => [],
        ];

        foreach ($purityProfiles as $metalType => $profiles) {
            foreach ($profiles as $profile) {
                $resolvedRates[$metalType][$this->pricing->normalizePurityString((float) $profile->purity_value)] = [
                    'label' => $profile->label,
                    'rate_per_gram' => $this->pricing->resolvedRateForToday(
                        $shop,
                        $metalType,
                        (float) $profile->purity_value
                    ),
                ];
            }
        }

        return $resolvedRates;
    }

    private function retailerPricingBlockedResponse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
