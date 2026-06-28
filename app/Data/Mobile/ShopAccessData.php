<?php

namespace App\Data\Mobile;

use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Manual "Close Shop" status for the mobile app, exposed via /bootstrap so the
 * app can show a Shop Closed screen on boot instead of waiting for a random
 * operational API to 403. Carries no business data — only the access flag.
 *
 * is_open == shop_access_enabled (both reflect the single flag); the owner
 * always has can_current_user_access=true even while the shop is closed.
 */
#[TypeScript]
class ShopAccessData extends Data
{
    public function __construct(
        public bool $is_open,
        public bool $shop_access_enabled,
        public bool $closed_by_owner,
        public ?string $message,
        public bool $can_current_user_access,
    ) {}

    public const CLOSED_MESSAGE = 'Shop is currently closed by the owner.';

    /**
     * Single source of truth for shop-access status. Reads the flag with a fresh
     * query (not a possibly-stale loaded relation) so it is correct right after a
     * PATCH. A missing row / null column = open. Owner always retains access.
     * These endpoints run inside TenantContext, so the BelongsToShop scope keeps
     * the lookup correctly scoped to the current shop.
     */
    public static function forShopUser(?Shop $shop, User $user): self
    {
        $flag = $shop
            ? ShopPreferences::where('shop_id', $shop->id)->value('shop_access_enabled')
            : null;
        $isOpen = $flag === null ? true : (bool) $flag;

        return new self(
            is_open: $isOpen,
            shop_access_enabled: $isOpen,
            closed_by_owner: ! $isOpen,
            message: $isOpen ? null : self::CLOSED_MESSAGE,
            can_current_user_access: $isOpen || $user->isOwner(),
        );
    }
}
