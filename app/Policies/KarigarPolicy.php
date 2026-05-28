<?php

namespace App\Policies;

use App\Models\Karigar;
use App\Models\User;

class KarigarPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('karigar.manage');
    }

    public function view(User $user, Karigar $karigar): bool
    {
        return $user->shop_id === $karigar->shop_id;
    }

    public function update(User $user, Karigar $karigar): bool
    {
        return $user->shop_id === $karigar->shop_id
            && $user->hasPermission('karigar.manage');
    }

    public function delete(User $user, Karigar $karigar): bool
    {
        return $user->shop_id === $karigar->shop_id
            && $user->hasPermission('karigar.manage');
    }
}
