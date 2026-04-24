<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    public function view(User $user, Vendor $vendor): bool
    {
        return $user->shop_id === $vendor->shop_id;
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->shop_id === $vendor->shop_id;
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->shop_id === $vendor->shop_id;
    }
}
