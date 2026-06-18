<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    /**
     * Known MANUAL-entry reasons, by direction. Single source of truth shared by
     * the web cashbook form, the web controller, and the mobile API so all three
     * agree. The mobile app's native screen should mirror these (see the mobile
     * build prompt). Automatic cash rows (sales, refunds, payouts) use their own
     * internal source_type values and are NOT in these lists.
     *
     * source_type is stored as free text, so a "custom" reason (anything not in
     * either list) is always allowed for either direction.
     */
    public const IN_SOURCES = [
        'customer_payment'  => 'Customer payment received',
        'customer_advance'  => 'Advance from customer',
        'old_gold_sold'     => 'Old gold / silver sold',
        'loan_received'     => 'Loan received',
        'owner_investment'  => 'Owner money put in',
        'opening_balance'   => 'Opening balance',
        'other_income'      => 'Other money in',
    ];

    public const OUT_SOURCES = [
        'karigar_payment'   => 'Karigar (worker) payment',
        'gold_purchase'     => 'Gold / silver purchase (supplier)',
        'supplier_payment'  => 'Supplier payment',
        'salary'            => 'Salary / wages',
        'rent'              => 'Shop rent',
        'utility_bills'     => 'Electricity / water / bills',
        'repair_charges'    => 'Repair / polishing charges',
        'marketing_expense' => 'Marketing / festival expense',
        'petty_expense'     => 'Petty / daily expense',
        'loan_repayment'    => 'Loan repayment',
        'owner_withdrawal'  => 'Owner money taken out',
        'other_expense'     => 'Other money out',
    ];

    /**
     * Whether a manual reason is consistent with the chosen direction. A KNOWN
     * money-in reason cannot be saved as money-out (and vice versa). Custom
     * free-text reasons are not in either list, so they pass for either side.
     */
    public static function reasonMatchesType(string $type, string $source): bool
    {
        if ($type === 'in'  && array_key_exists($source, self::OUT_SOURCES)) {
            return false;
        }
        if ($type === 'out' && array_key_exists($source, self::IN_SOURCES)) {
            return false;
        }

        return true;
    }

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }
}
