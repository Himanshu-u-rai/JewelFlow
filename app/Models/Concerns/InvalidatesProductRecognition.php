<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\ProductPromotionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Security writes and consent invalidation commit together, including quiet saves. */
trait InvalidatesProductRecognition
{
    public function save(array $options = [])
    {
        $fields = $this instanceof User
            ? ['shop_id', 'role_id', 'realm', 'password', 'is_active', 'employment_status']
            : ['name', 'shop_id'];
        if (! $this->exists || ! $this->isDirty($fields) || ! Schema::hasTable('product_recognitions')) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options, $fields) {
            $users = $this instanceof User ? [$this->id]
                : DB::table('users')->where('role_id', $this->id)->pluck('id')->all();
            ProductPromotionService::lockOwners($users);
            $saved = parent::save($options);
            if ($saved && $this->wasChanged($fields)) {
                ProductPromotionService::invalidateOwners($users);
            }

            return $saved;
        });
    }
}
