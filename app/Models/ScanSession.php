<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanSession extends Model
{
    /**
     * Presence semantics (Option B — delivery confirmation):
     *  - last_seen_at = the last time the POS terminal POLLED this session.
     *    It is the "POS is listening" heartbeat. A scanner treats the POS as
     *    listening when last_seen_at is within POS_LISTENING_WINDOW_SECONDS.
     *  - connected_user_id / device_install_id = the most recent device that
     *    connected. Multiple scanners may feed one session; per-scan ownership
     *    lives on scan_events.posted_by_user_id, not here.
     */
    public const POS_LISTENING_WINDOW_SECONDS = 8;

    protected $fillable = [
        'shop_id',
        'token',
        'status',
        'expires_at',
        'mobile_connected_at',
        'created_by',
        'connected_user_id',
        'device_install_id',
        'last_seen_at',
        'unpaired_at',
        'unpaired_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'mobile_connected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'unpaired_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScanEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    /**
     * True when the POS terminal has polled recently — i.e. it is actively
     * listening and a scan would reach the cart now.
     */
    public function isPosListening(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::POS_LISTENING_WINDOW_SECONDS));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('expires_at', '>', now());
    }
}
