<?php

namespace App\Policies;

use App\Models\CatalogPage;
use App\Models\User;

class CatalogPagePolicy
{
    public function update(User $user, CatalogPage $page): bool
    {
        return $user->shop_id === $page->shop_id;
    }

    public function delete(User $user, CatalogPage $page): bool
    {
        return $user->shop_id === $page->shop_id;
    }
}
