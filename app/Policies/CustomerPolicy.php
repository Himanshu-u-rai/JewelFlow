<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $user->shop_id === $customer->shop_id;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->shop_id === $customer->shop_id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->shop_id === $customer->shop_id;
    }
}
