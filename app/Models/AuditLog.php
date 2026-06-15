<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use LogicException;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit log is append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log is append-only.');
        });
    }

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'data',
        'actor',
        'target',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'data' => 'array',
        'actor' => 'array',
        'target' => 'array',
        'before' => 'array',
        'after' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * Actions that move money or gold, remove records, undo finalized work, or
     * change who can do what. These are the events a shop owner most wants to
     * spot at a glance, so the UI emphasises them. Matched on substrings of the
     * action name so new variants (e.g. *_deleted, *_reversed) are caught
     * without editing this list every time.
     */
    private const SENSITIVE_FRAGMENTS = [
        'delete', 'deleted', 'destroy',
        'reversal', 'reversed', 'reverse',
        'void', 'cancel', 'cancelled',
        'refund', 'override', 'overridden',
        'terminated', 'forfeit', 'write_off', 'writeoff',
        'vault_adjust', 'gold_adjust', 'gold_recovery',
        'role_permissions', 'rejected', 'defaulted',
        'device_revoked', 'session_revoked', 'sessions_revoked',
        // Returns / exchanges settle a credit note (money back to a customer),
        // and disposition/re-disposition changes the fate of returned stock —
        // both are money/gold movements an owner should notice.
        'return_order_settled', 'exchange_order_settled', 'exchange_unified_settled',
        'return_approval', 're_dispositioned',
    ];

    /**
     * Whether this entry is a sensitive/high-impact action worth highlighting.
     */
    public function isSensitive(): bool
    {
        $action = (string) $this->action;
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($action, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A plain-English line for this entry. Prefers the human description the
     * writer stored; falls back to a Title Case of the action code so an entry
     * is never blank or shown as a raw snake_case token.
     */
    public function summaryLine(): string
    {
        $description = trim((string) $this->description);
        if ($description !== '') {
            return $description;
        }
        return \Illuminate\Support\Str::headline((string) $this->action) ?: 'Activity';
    }

    /**
     * Turn the raw `data` payload into plain label/value rows a shop owner can
     * read like a receipt, instead of showing developer JSON. Generic by design
     * (works for keys we have never seen): snake_case → Title Case labels, money
     * keys formatted with ₹, ids as #n, booleans as Yes/No, nested lists summarised,
     * and empty/null values dropped entirely so an owner never sees "… : null".
     *
     * @return array<int, array{label:string, value:string}>
     */
    public function readableDetails(): array
    {
        $data = $this->data;
        if (! is_array($data) || $data === []) {
            return [];
        }

        $moneyKey = fn (string $k) => (bool) preg_match('/(price|total|amount|discount|round_off|paid|due|value|charge|refund)$/', $k)
            || in_array($k, ['total', 'subtotal', 'gst', 'round_off'], true);
        $money = fn ($v) => '₹' . number_format((float) $v, 2);

        // A few labels read better with an explicit name; everything else is
        // de-suffixed (_ids/_id) and Title Cased.
        $labelMap = [
            'customer_id' => 'Customer', 'item_ids' => 'Items', 'item_id' => 'Item',
            'invoice_id' => 'Invoice', 'lot_id' => 'Lot', 'lot_count' => 'Lots',
            'selling_price' => 'Selling price', 'round_off' => 'Round off',
            'manual_discount' => 'Manual discount', 'offer_discount' => 'Offer discount',
            'gold_used' => 'Gold used', 'fine_gold' => 'Fine gold', 'override_rate' => 'Rate used',
            'approver_id' => 'Approved by', 'reason' => 'Reason',
        ];
        $label = function (string $key) use ($labelMap): string {
            if (isset($labelMap[$key])) {
                return $labelMap[$key];
            }
            $key = preg_replace('/_ids$/', 's', $key);
            $key = preg_replace('/_id$/', '', $key);
            return \Illuminate\Support\Str::headline($key);
        };

        $rows = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            // Drop empties so nothing reads "Offer Scheme: (none)".
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_bool($value)) {
                $rows[] = ['label' => $label($key), 'value' => $value ? 'Yes' : 'No'];
                continue;
            }

            // Nested list (e.g. payments: [{mode, amount}], item_ids: [171]).
            if (is_array($value)) {
                $rows[] = ['label' => $label($key), 'value' => $this->summariseList($key, $value, $money)];
                continue;
            }

            if ($moneyKey($key) && is_numeric($value)) {
                if ((float) $value == 0.0) {
                    continue; // a zero discount/round-off is noise to an owner
                }
                $rows[] = ['label' => $label($key), 'value' => $money($value)];
                continue;
            }

            if (str_ends_with($key, '_id') && is_numeric($value)) {
                $rows[] = ['label' => $label($key), 'value' => '#' . $value];
                continue;
            }

            $rows[] = ['label' => $label($key), 'value' => (string) $value];
        }

        return $rows;
    }

    /**
     * Render a nested list value as one readable phrase.
     */
    private function summariseList(string $key, array $value, callable $money): string
    {
        // List of scalars (e.g. item_ids: [171, 172]) → "#171, #172"
        if (array_is_list($value) && ! is_array($value[0] ?? null)) {
            $isId = str_ends_with($key, '_ids') || str_ends_with($key, '_id');
            return collect($value)
                ->map(fn ($v) => $isId && is_numeric($v) ? '#' . $v : (string) $v)
                ->implode(', ');
        }

        // List of objects (e.g. payments: [{mode:cash, amount:43300}]) → "₹43,300 cash"
        if (array_is_list($value)) {
            return collect($value)->map(function ($row) use ($money) {
                $row = (array) $row;
                if (isset($row['amount'], $row['mode'])) {
                    return $money($row['amount']) . ' ' . str_replace('_', ' ', (string) $row['mode']);
                }
                return collect($row)->map(fn ($v, $k) => is_scalar($v) ? "{$k}: {$v}" : '')->filter()->implode(', ');
            })->filter()->implode(' · ');
        }

        // Associative object → "k: v, k: v"
        return collect($value)
            ->map(fn ($v, $k) => is_scalar($v) ? \Illuminate\Support\Str::headline((string) $k) . ': ' . $v : '')
            ->filter()->implode(', ');
    }

    /**
     * Classify the action into a visual category for the activity timeline:
     * a key, a one-word human label, a Heroicons stroke-path, and a tint pair
     * (icon background + icon colour Tailwind classes). Ordered most-specific
     * first so e.g. a refund matches "money" before a generic "settled".
     *
     * @return array{key:string,label:string,icon:string,bg:string,fg:string}
     */
    public function category(): array
    {
        $a = (string) $this->action;
        $has = fn (string ...$frags) => (bool) array_filter($frags, fn ($f) => str_contains($a, $f));

        // Heroicons (outline) path data, matching the inline-SVG style used app-wide.
        $icons = [
            'money'     => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'refund'    => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
            'gold'      => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'delete'    => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
            'inventory' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'staff'     => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
            'access'    => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
            'repair'    => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
            'sale'      => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17',
            'activity'  => 'M13 10V3L4 14h7v7l9-11h-7z',
        ];

        $make = fn (string $key, string $label, string $bg, string $fg) => [
            'key' => $key, 'label' => $label, 'icon' => $icons[$key] ?? $icons['activity'], 'bg' => $bg, 'fg' => $fg,
        ];

        return match (true) {
            $has('refund', 'return_order_settled', 'exchange_order_settled', 'exchange_unified_settled', 'return_approval')
                => $make('refund', 'Refund', 'bg-rose-100', 'text-rose-700'),
            $has('reversal', 'reversed', 'void', 'cancel', 'override', 'defaulted', 'rejected')
                => $make('refund', 'Reversal', 'bg-rose-100', 'text-rose-700'),
            $has('vault', 'gold_', 'bullion', 'metal', 'recovery', 'manufactur', 'stone')
                => $make('gold', 'Gold & vault', 'bg-amber-100', 'text-amber-700'),
            $has('delete', 'deleted', 'destroy', 'forfeit', 'write_off', 'writeoff')
                => $make('delete', 'Removed', 'bg-rose-100', 'text-rose-700'),
            $has('staff', 'terminated', 'reactivated')
                => $make('staff', 'Staff', 'bg-violet-100', 'text-violet-700'),
            $has('role_permissions', 'device_revoked', 'session_revoked', 'sessions_revoked', 'compliance', 'kyc')
                => $make('access', 'Access', 'bg-violet-100', 'text-violet-700'),
            $has('repair')
                => $make('repair', 'Repair', 'bg-sky-100', 'text-sky-700'),
            $has('invoice_payment', 'payment_recorded', 'cash_', 'installment', 'scheme_payment')
                => $make('money', 'Payment', 'bg-emerald-100', 'text-emerald-700'),
            $has('sale', 'invoice_finalized', 'quick_bill', 'emi_sale', 'exchange', 'scheme_redemption')
                => $make('sale', 'Sale', 'bg-emerald-100', 'text-emerald-700'),
            $has('item', 'purchase', 'stock', 'import', 'reorder', 'gold_lot', 'product')
                => $make('inventory', 'Inventory', 'bg-sky-100', 'text-sky-700'),
            default
                => $make('activity', 'Activity', 'bg-slate-100', 'text-slate-600'),
        };
    }
}
