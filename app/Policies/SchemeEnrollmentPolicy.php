<?php

namespace App\Policies;

use App\Models\SchemeEnrollment;
use App\Models\User;

class SchemeEnrollmentPolicy
{
    public function view(User $user, SchemeEnrollment $enrollment): bool
    {
        return $user->shop_id === $enrollment->shop_id;
    }

    public function update(User $user, SchemeEnrollment $enrollment): bool
    {
        return $user->shop_id === $enrollment->shop_id;
    }
}
