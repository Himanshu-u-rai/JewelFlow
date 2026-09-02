<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\ShopPaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * One tender against a historical document (V2 §7/§8).
 *
 * NEVER an `InvoicePayment`. This row never appends to the live cashbook, bank
 * ledger, wallet or store-credit balance — it exists purely to reconstruct
 * what a backdated bill's payment breakdown was.
 *
 * `shop_payment_method_id` is an OPTIONAL, read-only reference. The FK is a
 * plain single-column `ON DELETE SET NULL` (see the creating migration's
 * docblock for why it cannot be a composite shop-scoped FK here); this model
 * makes up the cross-shop integrity the FK cannot by rejecting, at write time,
 * any reference to a payment method belonging to a different shop.
 *
 * IMMUTABILITY (foundation-audit D1). Deliberately NOT `ImmutableWhenPublished`
 * — that trait's second check depends on `HistoricalLifecycle::unlocked()`, a
 * legitimate escape hatch for documents/lines that published payment evidence
 * must never have. Instead this model carries its own bespoke, no-unlock
 * `saving`/`deleting` guard (`assertParentDocumentIsStillDraft()`) that fails
 * EARLIER than, and independently of, the Postgres trigger
 * `historical_sales_payments_guard()` added in the corrective migration
 * (2026_09_18_000400). Payments get no unlock, full stop.
 */
class HistoricalSalesPayment extends Model
{
    use BelongsToShop;

    /** Reuses `InvoicePayment::VALID_MODES`' vocabulary only — never the model. */
    public const MODE_CASH       = 'cash';
    public const MODE_UPI        = 'upi';
    public const MODE_BANK       = 'bank';
    public const MODE_WALLET     = 'wallet';
    public const MODE_OLD_GOLD   = 'old_gold';
    public const MODE_OLD_SILVER = 'old_silver';
    public const MODE_OTHER      = 'other';
    public const MODE_EMI        = 'emi';
    public const MODE_SCHEME     = 'scheme';

    public const VALID_MODES = [
        self::MODE_CASH,
        self::MODE_UPI,
        self::MODE_BANK,
        self::MODE_WALLET,
        self::MODE_OLD_GOLD,
        self::MODE_OLD_SILVER,
        self::MODE_OTHER,
        self::MODE_EMI,
        self::MODE_SCHEME,
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'amount'                       => 'decimal:2',
        'payment_date'                 => 'date',
        'was_linked_to_payment_method' => 'boolean',
    ];

    protected static function booted(): void
    {
        /**
         * `deriveWasLinkedToPaymentMethod()` MUST run inside this `saving`
         * closure, not a separate `creating` one — see that method's docblock
         * for why. `saving` fires once per `save()` call (both insert and
         * update; the write-once guard inside the method itself is what
         * actually restricts the derivation to first-write), and critically
         * fires BEFORE Eloquent's `creating` event, which is the ordering
         * this fix depends on.
         */
        static::saving(function (self $payment): void {
            $payment->deriveWasLinkedToPaymentMethod();
            $payment->assertPaymentMethodBelongsToOwnShop();
            $payment->normalizeAccountLabelSnapshot();
            $payment->assertAccountLabelSnapshotIsPresent();
            $payment->assertParentDocumentIsStillDraft();
        });

        static::deleting(function (self $payment): void {
            $payment->assertParentDocumentIsStillDraft();
        });
    }

    /**
     * `was_linked_to_payment_method` is write-once, captured only at first
     * write time (never recomputed on update — see the class docblock and
     * the corrective migration's docblock for why a persisted marker
     * replaces the now-invalidated "null snapshot" heuristic).
     *
     * TWO distinct bugs were found and fixed here (chased with ad-hoc debug
     * output before this session — both are now confirmed root-caused, not
     * just symptom-silenced):
     *
     * Bug 1 — missing derivation. `$guarded = ['*']` on this model means a
     * caller almost always constructs rows via `forceFill()`/mass-assignment,
     * so an ordinary `protected $attributes = [...]` class-level default is
     * never reliably in play by the time any hook runs — and more
     * importantly, nothing was EVER deriving `true` from a caller-supplied
     * `shop_payment_method_id` in the first place. A row created with
     * `shop_payment_method_id` set but without the caller separately/
     * explicitly setting `was_linked_to_payment_method` silently kept the DB
     * column default (`false`), which is wrong: it WAS linked.
     * `displayAccountLabel()` would then never show "(No longer active)" for
     * such a row after its method was deleted, even though it has a real
     * preserved account snapshot. Fixed by this method: derive `true`/`false`
     * from `shop_payment_method_id !== null`, but only when the caller has
     * not already set the attribute explicitly (raw `DB::table()->insert()`
     * and tests that set it directly must not be overridden — this is a
     * derive-if-absent default, not an unconditional overwrite).
     *
     * Bug 2 — event-ordering vs. the app-wide Postgres boolean normalizer.
     * `AppServiceProvider::boot()` registers a wildcard `Event::listen(
     * 'eloquent.saving: *', ...)` listener that walks every model's
     * `getDirty()` attributes during the `saving` event and rewrites any
     * boolean-cast/boolean-column value to a DB-safe `'1'`/`'0'` string
     * (native PHP `bool` bound as a parameter is rejected by this Postgres
     * driver/prepare configuration with `SQLSTATE[42804]: column "x" is of
     * type boolean but expression is of type integer` — confirmed by
     * isolated probe). Eloquent fires `saving` BEFORE `creating` on every
     * insert. This method was originally invoked from a `static::creating()`
     * closure, i.e. AFTER the global normalizer's `saving`-time pass had
     * already run and already finished inspecting `getDirty()` — so a value
     * assigned here was never seen by the normalizer and reached the query
     * builder as a raw PHP `bool`, breaking every insert through this
     * derivation path. Fixed by moving the call into `booted()`'s
     * `static::saving()` closure instead (see that method for the ordering
     * rationale) so the freshly-derived value is dirty and visible to the
     * global normalizer before it runs.
     */
    private function deriveWasLinkedToPaymentMethod(): void
    {
        if (array_key_exists('was_linked_to_payment_method', $this->getAttributes())) {
            return;
        }

        $this->was_linked_to_payment_method = $this->shop_payment_method_id !== null;
    }

    /**
     * The composite `(child_id, shop_id) -> (id, shop_id)` FK pattern used
     * elsewhere in this module cannot be used here (a composite `ON DELETE
     * SET NULL` would null this row's own `shop_id`, see the migration
     * docblock) so this check is the only thing standing between a stray
     * write and a historical payment quietly pointing at another shop's bank
     * account. The corrective migration's Postgres trigger is the DB-level
     * backstop for the same rule against raw-SQL writes.
     */
    private function assertPaymentMethodBelongsToOwnShop(): void
    {
        if ($this->shop_payment_method_id === null) {
            return;
        }

        $method = ShopPaymentMethod::withoutTenant()->find($this->shop_payment_method_id);

        if ($method === null || (int) $method->shop_id !== (int) $this->shop_id) {
            throw new InvalidArgumentException(
                'A historical payment cannot reference a payment method from another shop.'
            );
        }
    }

    /**
     * D3 (foundation-audit) — snapshot-creation-time normalization, NOT a
     * display-time patch. A mode-derived label (e.g. "Cash") is synthesized
     * here ONLY for genuinely accountless rows that never referenced a
     * configured `ShopPaymentMethod`. A row that DOES reference one must
     * already carry that account's real label — this never overwrites a
     * caller-supplied snapshot and never invents one for a linked row.
     */
    private function normalizeAccountLabelSnapshot(): void
    {
        if ($this->account_label_snapshot !== null && trim((string) $this->account_label_snapshot) !== '') {
            return;
        }

        if ($this->shop_payment_method_id !== null) {
            // A configured account reference must supply its own real label;
            // deliberately NOT filled in here — assertAccountLabelSnapshotIsPresent()
            // will reject the blank rather than silently substitute the mode fallback.
            return;
        }

        $this->account_label_snapshot = ucfirst(str_replace('_', ' ', (string) $this->mode));
    }

    /**
     * D3 — app-layer half of the mandatory-snapshot rule; the corrective
     * migration's NOT NULL + non-blank CHECK is the DB-level backstop for
     * raw-SQL writes that skip this guard entirely.
     */
    private function assertAccountLabelSnapshotIsPresent(): void
    {
        if ($this->account_label_snapshot !== null && trim((string) $this->account_label_snapshot) !== '') {
            return;
        }

        throw new LogicException(
            'A historical payment must have a non-blank account_label_snapshot before it can be saved.'
        );
    }

    /**
     * D1 — the model's own, no-unlock immutability guard. Deliberately not
     * the `ImmutableWhenPublished` trait; see the class docblock for why.
     * Fires BEFORE the Postgres trigger `historical_sales_payments_guard()`
     * ever gets a chance to reject the same write, and throws a distinct
     * `LogicException` (not whatever class a rejected Postgres trigger
     * exception surfaces as) so the two layers are independently observable.
     */
    private function assertParentDocumentIsStillDraft(): void
    {
        if (! $this->isPublishedRecord()) {
            return;
        }

        $action = $this->exists ? 'modified or deleted' : 'inserted';

        throw new LogicException(sprintf(
            'Historical payment rows cannot be %s once the parent document is published '
            . '(payment %s, document %s).',
            $action,
            $this->exists ? (string) $this->getKey() : 'new',
            (string) $this->historical_sales_document_id
        ));
    }

    /**
     * Whether this payment's parent document has left the draft lifecycle
     * stage. Read WITHOUT the tenant scope for the same reason
     * `HistoricalSalesLine::isPublishedRecord()` does: a console/job context
     * that cannot resolve the parent must never silently downgrade a
     * published payment to editable. The database trigger enforces the same
     * rule independently.
     */
    public function isPublishedRecord(): bool
    {
        $status = HistoricalSalesDocument::withoutTenant()
            ->whereKey($this->historical_sales_document_id)
            ->value('status');

        return $status !== null && $status !== HistoricalSalesDocument::STATUS_DRAFT;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HistoricalSalesDocument::class, 'historical_sales_document_id');
    }

    /** Optional, read-only convenience link — see class docblock. */
    public function shopPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(ShopPaymentMethod::class);
    }

    /** True only when the reference still exists AND is currently active. */
    public function paymentMethodStillActive(): bool
    {
        if ($this->shop_payment_method_id === null) {
            return false;
        }

        $method = $this->relationLoaded('shopPaymentMethod')
            ? $this->shopPaymentMethod
            : ShopPaymentMethod::withoutTenant()->find($this->shop_payment_method_id);

        return (bool) ($method?->is_active ?? false);
    }

    /**
     * What every read path should render — the snapshot is authoritative
     * regardless of what happened to the live method afterwards (locked
     * decision: deleted/disabled methods stay visible, labelled
     * "No longer active").
     *
     * D3 cosmetic-nit fix: `account_label_snapshot` is mandatory for every
     * row now (D3), so it is never used as the "was this ever linked?"
     * signal any more — `was_linked_to_payment_method` (write-once at
     * creation) is. This is what stops a genuinely custom/free-text payment
     * that never had an FK from being mislabelled as though something was
     * deleted: only a row that WAS linked and no longer resolves to an
     * active method gets the suffix.
     */
    public function displayAccountLabel(): string
    {
        $label = $this->account_label_snapshot;

        $wasLinkedAndIsNowGone = $this->was_linked_to_payment_method && ! $this->paymentMethodStillActive();

        return $wasLinkedAndIsNowGone ? "{$label} (No longer active)" : $label;
    }
}
