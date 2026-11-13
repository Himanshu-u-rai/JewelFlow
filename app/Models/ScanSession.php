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

    /**
     * The one way a scan enters a session (web phone page and app; audit N7/N13). Decided under the session's row
     * lock: a session ended meanwhile takes nothing (null), and the same tag within 2 seconds is the earlier scan
     * (['event' => it, 'debounced' => true]). The lock is the session's, so it holds even when no scan exists yet.
     *
     * @return array{event: ScanEvent, debounced: bool}|null
     */
    public static function recordScan(int $sessionId, string $barcode, array $attribution = []): ?array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($sessionId, $barcode, $attribution) {
            $session = static::query()->whereKey($sessionId)->lockForUpdate()->first();
            if (! $session || ! $session->isActive()) {
                return null;
            }
            $recent = ScanEvent::where('scan_session_id', $sessionId)->where('barcode', $barcode)
                ->where('created_at', '>=', now()->subSeconds(2))->latest('id')->first();
            if ($recent) {
                return ['event' => $recent, 'debounced' => true];
            }

            return ['event' => ScanEvent::create(['scan_session_id' => $sessionId, 'barcode' => $barcode] + $attribution), 'debounced' => false];
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('expires_at', '>', now());
    }
}
