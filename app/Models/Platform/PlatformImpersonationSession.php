<?php

namespace App\Models\Platform;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformImpersonationSession extends Model
{
    protected $table = 'platform_impersonation_sessions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
