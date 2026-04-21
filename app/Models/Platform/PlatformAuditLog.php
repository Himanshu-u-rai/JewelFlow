<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PlatformAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'platform_audit_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Platform audit log is append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Platform audit log is append-only.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'actor_admin_id');
    }
}
