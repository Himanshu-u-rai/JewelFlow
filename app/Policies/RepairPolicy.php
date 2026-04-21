<?php

namespace App\Policies;

use App\Models\Repair;
use App\Models\User;

class RepairPolicy
{
    public function update(User $user, Repair $repair): bool
    {
        return $user->shop_id === $repair->shop_id;
    }

    public function delete(User $user, Repair $repair): bool
    {
        return $user->shop_id === $repair->shop_id && $repair->status !== 'delivered';
    }
}
