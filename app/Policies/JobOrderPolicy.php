<?php

namespace App\Policies;

use App\Models\JobOrder;
use App\Models\User;

class JobOrderPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('job_order.manage');
    }

    public function view(User $user, JobOrder $jobOrder): bool
    {
        return $user->shop_id === $jobOrder->shop_id;
    }

    public function update(User $user, JobOrder $jobOrder): bool
    {
        return $user->shop_id === $jobOrder->shop_id
            && $user->hasPermission('job_order.manage');
    }

    public function delete(User $user, JobOrder $jobOrder): bool
    {
        return $user->shop_id === $jobOrder->shop_id
            && $user->hasPermission('job_order.manage');
    }
}
