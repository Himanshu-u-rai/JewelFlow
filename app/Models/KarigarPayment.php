<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class KarigarPayment extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    // Linking an unlinked advance to an invoice after the fact is allowed.
    protected array $allowedUpdateColumns = ['karigar_invoice_id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    public function karigar()
    {
        return $this->belongsTo(Karigar::class);
    }

    public function invoice()
    {
        return $this->belongsTo(KarigarInvoice::class, 'karigar_invoice_id');
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(ShopPaymentMethod::class, 'payment_method_id');
    }
}
