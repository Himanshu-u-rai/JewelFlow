<?php

namespace App\Models;

use App\Models\Concerns\ArchivableParty;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Karigar extends Model
{
    // MASTERS PART 6: Karigar joins Customer/Vendor on the shared Part-3
    // archive/reactivate standard. ArchivableParty supplies the active()/
    // archived() scopes plus the two-layer eligibility (activeExistsRule +
    // lockActiveOrFail) so a disabled karigar can never receive a NEW item,
    // job or commitment while every historical row keeps resolving it.
    use ArchivableParty, BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'shop_name',
        'contact_person',
        'mobile',
        'email',
        'address',
        'city',
        'state',
        'pincode',
        'gst_number',
        'pan_number',
        'default_wastage_percent',
        'default_making_per_gram',
        'opening_balance',
        'opening_balance_at',
        'notes',
        // is_active is intentionally NOT fillable: lifecycle moves only through
        // archive()/reactivate() (ArchivesParties::setPartyActive), so an edit
        // form or crafted request can never flip it.
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_wastage_percent' => 'decimal:2',
        'default_making_per_gram' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'opening_balance_at' => 'date',
    ];

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class);
    }

    public function invoices()
    {
        return $this->hasMany(KarigarInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(KarigarPayment::class);
    }

    // Stock tagged to this karigar. Used by the delete guard so a karigar
    // referenced by items (items.karigar_id is nullOnDelete) cannot be hard
    // deleted out from under that history.
    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function getOutstandingBalanceAttribute(): float
    {
        $invoiced = (float) $this->invoices()->sum('total_after_tax');
        $paid = (float) $this->payments()->sum('amount');

        return (float) $this->opening_balance + $invoiced - $paid;
    }
}
