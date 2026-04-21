<?php

namespace App\Policies;

use App\Models\ReorderRule;
use App\Models\User;

class ReorderRulePolicy
{
    public function update(User $user, ReorderRule $rule): bool
    {
        return $user->shop_id === $rule->shop_id;
    }

    public function delete(User $user, ReorderRule $rule): bool
    {
        return $user->shop_id === $rule->shop_id;
    }
}
