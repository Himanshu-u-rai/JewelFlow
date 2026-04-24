<?php

namespace App\Policies;

use App\Models\PublicCatalogCollection;
use App\Models\User;

class CatalogCollectionPolicy
{
    public function create(User $user): bool
    {
        return $user->shop?->isRetailer() ?? false;
    }

    public function view(User $user, PublicCatalogCollection $collection): bool
    {
        return $user->shop_id === $collection->shop_id;
    }
}
