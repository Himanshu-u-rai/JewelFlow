<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scan_session_id',
        'barcode',
        'processed',
        'posted_by_user_id',
        'posted_by_token_id',
    ];

    protected $casts = [
        'processed'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class, 'scan_session_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
