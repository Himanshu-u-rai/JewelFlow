<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use LogicException;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit log is append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log is append-only.');
        });
    }

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'data',
        'actor',
        'target',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'data' => 'array',
        'actor' => 'array',
        'target' => 'array',
        'before' => 'array',
        'after' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
