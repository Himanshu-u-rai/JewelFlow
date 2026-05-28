<?php

namespace App\Policies;

use App\Models\ShopPaymentMethod;
use App\Models\User;

class ShopPaymentMethodPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('settings.edit');
    }

    public function update(User $user, ShopPaymentMethod $method): bool
    {
        return $user->shop_id === $method->shop_id;
    }

    public function delete(User $user, ShopPaymentMethod $method): bool
    {
        return $user->shop_id === $method->shop_id;
    }
}
