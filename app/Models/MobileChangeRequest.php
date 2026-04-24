<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileChangeRequest extends Model
{
    protected $fillable = [
        'user_id',
        'new_mobile_number',
        'otp_hash',
        'expires_at',
        'attempts',
        'verified_at',
        'consumed_at',
        'requested_ip',
        'user_agent',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected $hidden = ['otp_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->isAfter($this->expires_at);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
