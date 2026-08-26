<?php

namespace App\Models;

use App\Models\Concerns\ArchivableParty;
use App\Models\Concerns\CanonicalisesMobileNumbers;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToShop, ArchivableParty, CanonicalisesMobileNumbers;

    /** @var array<int, string> */
    protected static array $mobileColumns = ['mobile'];

    /**
     * MASTERS PART 3: `is_active` is deliberately NOT fillable. Lifecycle is
     * changed only by the explicit archive/reactivate endpoints (which use
     * forceFill, matching StaffController's terminate/reactivate precedent), so
     * no edit form, mass assignment or crafted legacy request can flip it.
     */
    protected $fillable = [
        'name',
        'contact_person',
        'mobile',
        'email',
        'address',
        'city',
        'state',
        'gst_number',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function reorderRules()
    {
        return $this->hasMany(ReorderRule::class);
    }
}
