<?php

namespace App\Services;

use App\Models\CatalogCollectionItem;
use App\Models\Item;
use App\Models\PublicCatalogCollection;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogShareService
{
    // ──────────────────────────────────────────────
    // Token & URL helpers
    // ──────────────────────────────────────────────

    /**
     * Ensure an item has a share token, creating one if missing.
     * Returns the token string.
     */
    public function ensureItemHasToken(Item $item): string
    {
        if (!blank($item->share_token)) {
            return $item->share_token;
        }

        $token = $this->generateUniqueItemToken();
        $item->fill(['share_token' => $token])->save();

        return $token;
    }

    /**
     * Batch-assign share tokens to items that don't have one.
     * Avoids N+1 queries when listing multiple items.
     *
     * @param \Illuminate\Support\Collection<int, Item> $items
     */
    public function ensureItemsHaveTokens(\Illuminate\Support\Collection $items): void
    {
        $needTokens = $items->filter(fn (Item $item) => blank($item->share_token));

        if ($needTokens->isEmpty()) {
            return;
        }

        foreach ($needTokens as $item) {
            $item->share_token = (string) Str::ulid();
        }

        // Batch update in a single query per item (no uniqueness check needed - ULIDs are unique)
        DB::transaction(function () use ($needTokens) {
            foreach ($needTokens as $item) {
                $item->saveQuietly();
            }
        });
    }

    /**
     * Build a signed temporary URL for a single public item page.
     */
    public function buildItemUrl(string $token): string
    {
        return URL::temporarySignedRoute(
            'catalog.public.show',
            now()->addDays($this->ttlDays()),
            ['token' => $token]
        );
    }

    /**
     * Build a signed temporary URL for a public collection page.
     */
    public function buildCollectionUrl(string $token): string
    {
        return URL::temporarySignedRoute(
            'catalog.public.collection.show',
            now()->addDays($this->ttlDays()),
            ['token' => $token]
        );
    }

    /**
     * Build a permanent (non-signed) URL for a product on the catalog website.
     */
    public function buildCatalogProductUrl(Shop $shop, string $token): string
    {
        return route('catalog.website.product', [
            'slug'  => $shop->catalog_slug,
            'token' => $token,
        ]);
    }

    /**
     * Build a permanent (non-signed) URL for a collection on the catalog website.
     */
    public function buildCatalogCollectionUrl(Shop $shop, string $token): string
    {
        return route('catalog.website.collection', [
            'slug'  => $shop->catalog_slug,
            'token' => $token,
        ]);
    }

    // ──────────────────────────────────────────────
    // Image resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve a full public image URL for an item.
     * Returns null when no image is set.
     */
    public function resolveImageUrl(Request $request, Item $item): ?string
    {
        if (empty($item->image)) {
            return null;
        }

        $rawImage = trim((string) $item->image);

        if (Str::startsWith($rawImage, ['http://', 'https://'])) {
            return $rawImage;
        }

        $normalizedPath = preg_replace('/^storage\//', '', ltrim($rawImage, '/'));
        $storageUrl     = Storage::disk('public')->url($normalizedPath);
        $pathOnly       = parse_url($storageUrl, PHP_URL_PATH) ?: $storageUrl;

        return $request->getSchemeAndHttpHost() . '/' . ltrim((string) $pathOnly, '/');
    }

    // ──────────────────────────────────────────────
    // Collection management
    // ──────────────────────────────────────────────

    /**
     * Validate that all given item IDs belong to a specific shop and are in stock.
     *
     * @throws ValidationException
     */
    public function validateItemsBelongToShop(int $shopId, array $itemIds): void
    {
        $validCount = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->whereIn('id', $itemIds)
            ->count();

        if ($validCount !== count($itemIds)) {
            throw ValidationException::withMessages([
                'item_ids' => 'Some selected items do not belong to your shop or are not in stock.',
            ]);
        }
    }

    /**
     * Create a new public catalog collection with pivot rows.
     * Validates ownership, wraps everything in a transaction.
     */
    public function createCollection(
        Shop $shop,
        User $user,
        array $itemIds,
        ?string $title
    ): PublicCatalogCollection {
        $itemIds = collect($itemIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->validateItemsBelongToShop($shop->id, $itemIds);

        return DB::transaction(function () use ($shop, $user, $itemIds, $title) {
            $collection = PublicCatalogCollection::create([
                'shop_id'             => $shop->id,
                'created_by_user_id'  => $user->id,
                'token'               => $this->generateUniqueCollectionToken(),
                'title'               => $title,
                'item_ids'            => $itemIds, // kept for backward compat
                'expires_at'          => now()->addDays($this->ttlDays()),
            ]);

            $now  = now()->toDateTimeString();
            $rows = array_map(fn ($id) => [
                'collection_id' => $collection->id,
                'item_id'       => $id,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], $itemIds);

            CatalogCollectionItem::insert($rows);

            return $collection;
        });
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    private function ttlDays(): int
    {
        return max(1, (int) config('catalog.share_link_ttl_days', 30));
    }

    private function generateUniqueItemToken(): string
    {
        do {
            $token = (string) Str::ulid();
        } while (Item::withoutTenant()->where('share_token', $token)->exists());

        return $token;
    }

    private function generateUniqueCollectionToken(): string
    {
        do {
            $token = (string) Str::ulid();
        } while (PublicCatalogCollection::withoutTenant()->where('token', $token)->exists());

        return $token;
    }
}
