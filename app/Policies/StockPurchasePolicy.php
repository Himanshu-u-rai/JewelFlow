<?php

namespace App\Policies;

use App\Models\StockPurchase;
use App\Models\User;

class StockPurchasePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('inventory.create');
    }

    public function view(User $user, StockPurchase $purchase): bool
    {
        return $user->shop_id === $purchase->shop_id;
    }

    public function update(User $user, StockPurchase $purchase): bool
    {
        return $user->shop_id === $purchase->shop_id
            && $user->hasPermission('inventory.edit');
    }

    public function delete(User $user, StockPurchase $purchase): bool
    {
        return $user->shop_id === $purchase->shop_id
            && $user->hasPermission('inventory.delete');
    }
}
