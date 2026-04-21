<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Item;
use App\Models\Shop;
use App\Models\Vendor;
use App\Services\CatalogShareService;
use App\Services\PosSearchCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function __construct(private CatalogShareService $catalog) {}

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
            $rules = array_merge($rules, [
                'gross_weight' => 'sometimes|required|numeric|min:0.001',
                'stone_weight' => 'nullable|numeric|min:0',
                'purity' => 'sometimes|required|numeric|min:1|max:24',
                'cost_price' => 'sometimes|required|numeric|min:0',
                'selling_price' => 'sometimes|required|numeric|min:0',
                'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
                'huid' => [
                    'nullable', 'string', 'max:30',
                    Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid')->ignore($item->id),
                ],
                'hallmark_date' => 'nullable|date',
            ]);
        }

        $validated = $request->validate($rules);

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

        DB::transaction(function () use ($item, $validated, $isRetailer, $newImagePath, $clearImage) {
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
                if (array_key_exists('gross_weight', $validated)) {
                    $updates['gross_weight'] = $validated['gross_weight'];
                    $updates['stone_weight'] = $validated['stone_weight'] ?? 0;
                    $updates['net_metal_weight'] = $validated['gross_weight'] - ($validated['stone_weight'] ?? 0);
                }
                foreach (['purity', 'cost_price', 'selling_price'] as $numeric) {
                    if (array_key_exists($numeric, $validated)) {
                        $updates[$numeric] = $validated[$numeric];
                    }
                }
                foreach (['vendor_id', 'huid', 'hallmark_date'] as $nullable) {
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

        return [
            'id' => $item->id,
            'barcode' => $item->barcode,
            'design' => $item->design,
            'category' => $item->category,
            'sub_category' => $item->sub_category,
            'gross_weight' => (float) $item->gross_weight,
            'stone_weight' => (float) $item->stone_weight,
            'net_metal_weight' => (float) $item->net_metal_weight,
            'purity' => (float) $item->purity,
            'cost_price' => (float) $item->cost_price,
            'selling_price' => (float) $item->selling_price,
            'making_charges' => (float) $item->making_charges,
            'stone_charges' => (float) $item->stone_charges,
            'status' => $item->status,
            'huid' => $item->huid,
            'hallmark_date' => $item->hallmark_date?->toDateString(),
            'image' => $this->catalog->resolveImageUrl($request, $item),
            'share_url' => $this->buildProductShareUrl($shop, $token),
            'vendor_id' => $item->vendor_id,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'barcode' => [
                'required', 'string', 'max:100',
                Rule::unique('items', 'barcode')->where('shop_id', $shopId),
            ],
            'design' => 'nullable|string|max:255',
            'category' => 'required|string|max:255',
            'sub_category' => 'nullable|string|max:255',
            'gross_weight' => 'required|numeric|min:0.001',
            'stone_weight' => 'nullable|numeric|min:0',
            'purity' => 'required|numeric|min:1|max:24',
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone_charges' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'huid' => [
                'nullable', 'string', 'max:30',
                Rule::unique('items', 'huid')->where('shop_id', $shopId)->whereNotNull('huid'),
            ],
            'hallmark_date' => 'nullable|date',
            'image_base64' => 'nullable|string',
        ]);

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

        $netMetalWeight = $validated['gross_weight'] - ($validated['stone_weight'] ?? 0);

        $item = DB::transaction(function () use ($shopId, $validated, $netMetalWeight, $imagePath) {
            $item = Item::create([
                'shop_id' => $shopId,
                'barcode' => $validated['barcode'],
                'design' => $validated['design'] ?? null,
                'category' => $validated['category'],
                'sub_category' => $validated['sub_category'] ?? null,
                'gross_weight' => $validated['gross_weight'],
                'stone_weight' => $validated['stone_weight'] ?? 0,
                'net_metal_weight' => $netMetalWeight,
                'purity' => $validated['purity'],
                'making_charges' => $validated['making_charges'] ?? 0,
                'stone_charges' => $validated['stone_charges'] ?? 0,
                'cost_price' => $validated['cost_price'],
                'selling_price' => $validated['selling_price'],
                'vendor_id' => $validated['vendor_id'] ?? null,
                'huid' => $validated['huid'] ?? null,
                'hallmark_date' => $validated['hallmark_date'] ?? null,
                'source' => 'purchased',
                'status' => 'in_stock',
                'image' => $imagePath,
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
                ],
            ]);

            return $item;
        });

        return response()->json([
            'id' => $item->id,
            'barcode' => $item->barcode,
            'design' => $item->design,
            'category' => $item->category,
            'selling_price' => (float) $item->selling_price,
            'message' => 'Item registered successfully.',
        ], 201);
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

    private function buildProductShareUrl(?Shop $shop, string $token): string
    {
        if ((bool) ($shop && !blank($shop->catalog_slug) && $shop->catalogWebsiteSettings?->is_enabled)) {
            return $this->catalog->buildCatalogProductUrl($shop, $token);
        }

        return $this->catalog->buildItemUrl($token);
    }
}
