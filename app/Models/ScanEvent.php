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

    /**
     * Why a scanned barcode cannot go in the POS cart, or null when it can: it
     * must be an in-stock item of the shop (the POS lists only those). Matched
     * as the POS matches: spaces ignored, and leading zeros on a numeric code.
     * Shared by the app's /scan/send and the web's phone scanner page.
     */
    public static function stockProblem(int $shopId, string $barcode): ?string
    {
        $code = preg_replace('/\s+/', '', $barcode);
        $item = Item::withoutTenant()
            ->where('shop_id', $shopId)
            ->when(
                ctype_digit($code),
                fn ($q) => $q->whereRaw("ltrim(replace(barcode, ' ', ''), '0') = ?", [ltrim($code, '0')]),
                fn ($q) => $q->whereRaw("replace(barcode, ' ', '') = ?", [$code]),
            )
            ->orderByRaw("status = 'in_stock' desc")
            ->first(['id', 'status']);

        if (! $item) {
            return "No item with barcode {$barcode} in your stock.";
        }

        return $item->status === 'in_stock'
            ? null
            : "{$barcode} is not in stock (" . str_replace('_', ' ', $item->status) . ').';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class, 'scan_session_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
