<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Services\CatalogShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(private CatalogShareService $catalog) {}

    public function items(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;
        $shop = $request->user()->shop;
        if ($shop) {
            $shop->loadMissing('catalogWebsiteSettings');
        }

        $query = Item::query()
            ->where('shop_id', $shopId)
            ->where('status', 'in_stock');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($builder) use ($search): void {
                $builder->where('barcode', 'ilike', "%{$search}%")
                    ->orWhere('design', 'ilike', "%{$search}%")
                    ->orWhere('category', 'ilike', "%{$search}%")
                    ->orWhere('sub_category', 'ilike', "%{$search}%")
                    ->orWhere('huid', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }

        $matchingCount = (clone $query)->count();

        $perPage = (int) $request->integer('per_page', 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $perPage = min($perPage, 80);

        $items = $query
            ->latest()
            ->paginate($perPage);

        $collection = $items->getCollection();
        $this->catalog->ensureItemsHaveTokens($collection);

        $data = $collection->map(function (Item $item) use ($request, $shop): array {
            return [
                'id' => (int) $item->id,
                'barcode' => (string) $item->barcode,
                'design' => $item->design,
                'category' => (string) ($item->category ?? ''),
                'sub_category' => $item->sub_category,
                'purity' => $item->purity !== null ? (float) $item->purity : null,
                'gross_weight' => $item->gross_weight !== null ? (float) $item->gross_weight : null,
                'selling_price' => $item->selling_price !== null ? (float) $item->selling_price : null,
                'huid' => $item->huid,
                'image_url' => $this->catalog->resolveImageUrl($request, $item),
                'share_url' => $this->buildProductShareUrl($shop, (string) $item->share_token),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'matching_count' => $matchingCount,
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $categories = Item::query()
            ->where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function template(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;
        $preferences = $shop?->preferences;

        return response()->json([
            'header' => $preferences?->wa_custom_header ?: $this->defaultHeader((string) ($shop?->name ?? '')),
            'body' => $preferences?->wa_custom_body ?: $this->defaultBody(),
            'footer' => $preferences?->wa_custom_footer ?: $this->defaultFooter(),
        ]);
    }

    public function updateTemplate(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;

        $validated = $request->validate([
            'header' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:2000',
            'footer' => 'nullable|string|max:255',
        ]);

        $preferences = $shop->preferences ?? ShopPreferences::firstOrCreate([
            'shop_id' => $shop->id,
        ]);

        $preferences->fill([
            'wa_custom_header' => $validated['header'] ?? null,
            'wa_custom_body' => $validated['body'] ?? null,
            'wa_custom_footer' => $validated['footer'] ?? null,
        ]);
        $preferences->save();

        return response()->json([
            'message' => 'Catalog WhatsApp template saved.',
            'template' => [
                'header' => $preferences->wa_custom_header ?: $this->defaultHeader((string) $shop->name),
                'body' => $preferences->wa_custom_body ?: $this->defaultBody(),
                'footer' => $preferences->wa_custom_footer ?: $this->defaultFooter(),
            ],
        ]);
    }

    public function storeCollection(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;
        if ($shop) {
            $shop->loadMissing('catalogWebsiteSettings');
        }

        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);

        $itemIds = collect($validated['item_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($itemIds)) {
            return response()->json(['message' => 'Select at least one valid product.'], 422);
        }

        try {
            $collection = $this->catalog->createCollection(
                $request->user()->shop,
                $request->user(),
                $itemIds,
                $validated['title'] ?? null
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first(),
            ], 422);
        }

        return response()->json([
            'message' => 'Collection link created successfully.',
            'url' => $this->buildCollectionShareUrl($shop, (string) $collection->token),
            'count' => count($itemIds),
        ]);
    }

    private function buildProductShareUrl(?Shop $shop, string $token): string
    {
        return $this->catalog->buildPreferredProductUrl($shop, $token);
    }

    private function buildCollectionShareUrl(?Shop $shop, string $token): string
    {
        return $this->catalog->buildPreferredCollectionUrl($shop, $token);
    }

    private function defaultHeader(string $shopName): string
    {
        $trimmed = trim($shopName);
        $name = $trimmed !== '' ? $trimmed : 'Our Jewellery Store';

        return "*{$name}*";
    }

    private function defaultBody(): string
    {
        return "*{design}*\nCode: {barcode}\nCategory: {category}{subcategory_suffix}\nPurity: {purity}\nWeight: {weight} g\nPrice: ₹{price}\n{offer_line}\n{share_url_line}";
    }

    private function defaultFooter(): string
    {
        return 'Reply on WhatsApp to book now.';
    }
}
