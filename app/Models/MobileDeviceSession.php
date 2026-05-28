<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Immutable session record — one row per login event.
 *
 * token_id is nullable: set at login, cleared via ON DELETE SET NULL when
 * Sanctum removes the token for any reason. Ended sessions retain their row
 * for audit history.
 *
 * ended_reason values: 'logout' | 'replaced' | 'revoked' | 'terminated' | 'stale_pruned'
 */
class MobileDeviceSession extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'user_id',
        'device_uuid',
        'device_name',
        'platform',
        'app_version',
        'os_version',
        'ip_address',
        'token_id',
        'logged_in_at',
        'last_seen_at',
        'logged_out_at',
        'ended_reason',
    ];

    protected $casts = [
        'logged_in_at'  => 'datetime',
        'last_seen_at'  => 'datetime',
        'logged_out_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Live Sanctum token — null when session has ended and token was deleted. */
    public function sanctumToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    public function isActive(): bool
    {
        return $this->logged_out_at === null;
    }

    /**
     * Snapshot last_used_at from the Sanctum token, mark session as ended.
     * Call this BEFORE deleting the token so the timestamp is still accessible.
     */
    public function endSession(string $reason): void
    {
        if ($this->logged_out_at !== null) {
            return; // already ended
        }

        $lastUsed = null;
        if ($this->token_id) {
            $lastUsed = \Illuminate\Support\Facades\DB::table('personal_access_tokens')
                ->where('id', $this->token_id)
                ->value('last_used_at');
        }

        $this->update([
            'logged_out_at' => now(),
            'ended_reason'  => $reason,
            'last_seen_at'  => $lastUsed ? \Carbon\Carbon::parse($lastUsed) : $this->logged_in_at,
        ]);
    }
}
