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
    ];

    protected $casts = [
        'processed'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class, 'scan_session_id');
    }
}
