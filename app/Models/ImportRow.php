<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'import_id',
        'shop_id',
        'row_number',
        'status',
        'error_message',
        'payload',
        'computed',
    ];

    protected $casts = [
        'payload' => 'array',
        'computed' => 'array',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }
}
