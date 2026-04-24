<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = [
        'mobile_number',
        'otp_code',
        'purpose',
        'expires_at',
        'attempts',
        'resend_count',
        'last_sent_at',
        'verified_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Check if OTP is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Scope to get valid (non-expired) OTPs.
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to get OTPs for a specific mobile number.
     */
    public function scopeForMobile($query, string $mobile)
    {
        return $query->where('mobile_number', $mobile);
    }
}
