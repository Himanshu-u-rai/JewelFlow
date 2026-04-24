<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    public function view(User $user, Item $item): bool
    {
        return $user->shop_id === $item->shop_id;
    }

    // Ownership only — the controller redirects with a friendly message when
    // the item is not in_stock. Checking status here would fire a 403 instead.
    public function update(User $user, Item $item): bool
    {
        return $user->shop_id === $item->shop_id;
    }

    public function delete(User $user, Item $item): bool
    {
        return $user->shop_id === $item->shop_id;
    }
}
