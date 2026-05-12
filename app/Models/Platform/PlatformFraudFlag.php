<?php
namespace App\Models\Platform;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFraudFlag extends Model
{
    protected $table = 'platform_fraud_flags';
    protected $fillable = ['shop_id', 'flag_type', 'flag_data', 'reviewed', 'reviewed_by', 'reviewed_at', 'review_notes'];
    protected $casts = ['flag_data' => 'array', 'reviewed' => 'boolean', 'reviewed_at' => 'datetime'];

    public const TYPE_INVOICE_SPIKE     = 'invoice_spike';
    public const TYPE_BULK_CUSTOMERS    = 'bulk_customers';
    public const TYPE_CROSS_TENANT_PAN  = 'cross_tenant_pan';
    public const TYPE_INACTIVE_SUBSCRIBER = 'inactive_subscriber';

    public function shop(): BelongsTo { return $this->belongsTo(Shop::class); }
}
