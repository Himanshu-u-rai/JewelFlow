<?php

namespace App\Services;

use App\Models\AuditLog;

class AccountingAuditService
{
    public static function log(array $payload): void
    {
        AuditLog::create([
            'shop_id' => $payload['shop_id'],
            'user_id' => $payload['user_id'] ?? auth()->id(),
            'action' => $payload['action'],
            'model_type' => $payload['model_type'] ?? 'financial',
            'model_id' => $payload['model_id'] ?? 0,
            'description' => $payload['description'] ?? null,
            'data' => $payload['data'] ?? null,
            'actor' => [
                'id' => auth()->id(),
                'type' => 'user',
            ],
            'target' => $payload['target'] ?? null,
            'before' => $payload['before'] ?? null,
            'after' => $payload['after'] ?? null,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
