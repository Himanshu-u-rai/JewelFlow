<?php

namespace App\Policies;

use App\Models\Scheme;
use App\Models\User;

class SchemePolicy
{
    public function view(User $user, Scheme $scheme): bool
    {
        return $user->shop_id === $scheme->shop_id;
    }

    public function update(User $user, Scheme $scheme): bool
    {
        return $user->shop_id === $scheme->shop_id;
    }

    public function delete(User $user, Scheme $scheme): bool
    {
        return $user->shop_id === $scheme->shop_id;
    }

    public function enroll(User $user, Scheme $scheme): bool
    {
        return $user->shop_id === $scheme->shop_id;
    }
}
