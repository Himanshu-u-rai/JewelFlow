<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceAlert extends Model
{
    use BelongsToShop;

    public const TYPE_SPLIT_TRANSACTION = 'split_transaction';
    public const TYPE_MISSING_PAN       = 'missing_pan';
    public const TYPE_THRESHOLD_BREACH  = 'threshold_breach';

    protected $fillable = [
        'shop_id',
        'customer_id',
        'invoice_id',
        'alert_type',
        'alert_data',
        'resolved',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'alert_data'  => 'array',
        'resolved'    => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolve(int $userId, string $notes = ''): void
    {
        // Use DB::table to bypass immutability concerns on related models.
        // DB::raw for the boolean — native pgsql prepared statements reject a
        // bound PHP bool against a boolean column.
        \Illuminate\Support\Facades\DB::table('compliance_alerts')
            ->where('id', $this->id)
            ->update([
                'resolved'          => \Illuminate\Support\Facades\DB::raw('true'),
                'resolved_by'       => $userId,
                'resolved_at'       => now(),
                'resolution_notes'  => $notes,
                'updated_at'        => now(),
            ]);
    }
}
