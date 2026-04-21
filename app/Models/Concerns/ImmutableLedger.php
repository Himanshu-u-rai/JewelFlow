<?php

namespace App\Models\Concerns;

use App\Services\AccountingAuditService;
use LogicException;

trait ImmutableLedger
{
    protected static function bootImmutableLedger(): void
    {
        static::updating(function ($model) {
            static::logMutationAttempt($model, 'update_blocked');
            throw new LogicException(static::class . ' is append-only and cannot be updated.');
        });

        static::deleting(function ($model) {
            static::logMutationAttempt($model, 'delete_blocked');
            throw new LogicException(static::class . ' is append-only and cannot be deleted.');
        });
    }

    protected static function logMutationAttempt($model, string $action): void
    {
        try {
            if (!auth()->check()) {
                return;
            }

            AccountingAuditService::log([
                'shop_id' => $model->shop_id ?? auth()->user()?->shop_id,
                'action' => 'financial_mutation_' . $action,
                'model_type' => class_basename($model),
                'model_id' => $model->id,
                'description' => static::class . ' mutation attempt blocked.',
                'before' => method_exists($model, 'getOriginal') ? $model->getOriginal() : null,
                'after' => method_exists($model, 'getAttributes') ? $model->getAttributes() : null,
                'target' => [
                    'type' => class_basename($model),
                    'id' => $model->id,
                ],
            ]);
        } catch (\Throwable) {
            // Never break core write paths because audit log write failed.
        }
    }
}
