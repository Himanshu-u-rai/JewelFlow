<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CashTransaction extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'is_opening' => 'boolean',
    ];

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
     * The plain name of an entry's reason, as on the entry form ("petty_expense"
     * → "Petty / daily expense"); other codes read as words. Same rule as the
     * app's cashbookSourceLabel(), so the web list and the app say the same.
     */
    public static function sourceLabel(?string $source, string $type): string
    {
        if ($source === null || trim($source) === '') {
            return $type === 'in' ? 'Money in' : 'Money out';
        }

        return self::IN_SOURCES[$source] ?? self::OUT_SOURCES[$source]
            ?? ucfirst(trim(preg_replace('/[_-]+/', ' ', $source)));
    }

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

    /**
     * The account a manual entry went through, checked the same way for the web
     * form and the app (as quick bill payments are): only UPI, bank and wallet
     * have one; once the shop has a switched-on account of that kind, one must be
     * picked; it must be the shop's own, switched on, and of that kind. A shop
     * with no account of that kind keeps saving without one.
     *
     * @throws ValidationException on payment_method_id
     */
    public static function resolveAccount(int $shopId, string $mode, mixed $accountId): ?ShopPaymentMethod
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['payment_method_id' => $message]);
        $picked = $accountId !== null && $accountId !== '';

        if (! in_array($mode, ShopPaymentMethod::TYPES, true)) {
            return $picked ? $fail('Only UPI, bank and wallet payments go to an account.') : null;
        }

        $kind = ShopPaymentMethod::TYPE_LABELS[$mode];
        $accounts = ShopPaymentMethod::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('type', $mode)
            ->active()
            ->get();

        if (! $picked) {
            return $accounts->isEmpty() ? null : $fail("Choose which {$kind} account the money went through.");
        }

        return $accounts->firstWhere('id', (int) $accountId)
            ?? $fail("Choose one of the shop's {$kind} accounts.");
    }

    /**
     * The account columns for a cash row written alongside a payment that chose
     * one (sale tenders, EMI payments), so the cash book can show and filter it.
     */
    public static function accountColumns(?int $paymentMethodId): array
    {
        $method = $paymentMethodId ? ShopPaymentMethod::withoutTenant()->find($paymentMethodId) : null;

        return [
            'payment_method_id' => $method?->id,
            'payment_method_label_snapshot' => $method?->account_label,
        ];
    }

    /**
     * The cash book's snapshot strip: today's and this month's money in / out,
     * every payment kind. One query path for the web page and the app, so both
     * show the same figures.
     *
     * Opening-balance rows must not inflate these: they are a starting position,
     * not a day/month movement. (The "Money on Hand" figure still counts them,
     * correctly, via LedgerService as opening.)
     *
     * @return array{today_in: float, today_out: float, month_in: float, month_out: float}
     */
    public static function snapshot(int $shopId): array
    {
        $sums = "SUM(CASE WHEN type = 'in'  THEN amount ELSE 0 END) as money_in,
                 SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END) as money_out";

        $today = self::where('shop_id', $shopId)
            ->whereDate('created_at', now()->toDateString())
            ->whereRaw('is_opening IS NOT TRUE')
            ->selectRaw($sums)
            ->first();

        $month = self::where('shop_id', $shopId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->whereRaw('is_opening IS NOT TRUE')
            ->selectRaw($sums)
            ->first();

        return [
            'today_in'  => (float) ($today->money_in  ?? 0),
            'today_out' => (float) ($today->money_out ?? 0),
            'month_in'  => (float) ($month->money_in  ?? 0),
            'month_out' => (float) ($month->money_out ?? 0),
        ];
    }

    /**
     * Money in / out for each of the given days, over an already-filtered cash
     * book query (so a day split across pages, or narrowed to UPI, still adds up).
     * Opening balances are not collections or expenses, as the web's Today strip.
     *
     * @param  list<string>  $dates  Y-m-d
     * @return array<string, array{date: string, money_in: float, money_out: float}> keyed by date
     */
    public static function dayTotals(\Illuminate\Database\Eloquent\Builder $filtered, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return (clone $filtered)->reorder()->setEagerLoads([])
            ->whereRaw('is_opening IS NOT TRUE')
            ->whereIn(\Illuminate\Support\Facades\DB::raw('DATE(created_at)'), $dates)
            ->selectRaw("DATE(created_at) AS day,
                SUM(CASE WHEN type = 'in'  THEN amount ELSE 0 END) AS money_in,
                SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END) AS money_out")
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->day => [
                'date' => (string) $r->day,
                'money_in' => round((float) $r->money_in, 2),
                'money_out' => round((float) $r->money_out, 2),
            ]])
            ->all();
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
