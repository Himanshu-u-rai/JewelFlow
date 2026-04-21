<?php

namespace App\Policies;

use App\Models\SubCategory;
use App\Models\User;

class SubCategoryPolicy
{
    public function update(User $user, SubCategory $subCategory): bool
    {
        return $user->shop_id === $subCategory->shop_id;
    }

    public function delete(User $user, SubCategory $subCategory): bool
    {
        return $user->shop_id === $subCategory->shop_id;
    }
}
