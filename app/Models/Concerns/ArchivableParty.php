<?php

namespace App\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * MASTERS PART 3 — the single reusable archive/reactivate eligibility mechanism
 * shared by Customer and Vendor ("parties").
 *
 * A party is archived (is_active = false) to stop it being used for NEW
 * commercial commitments while every historical invoice, purchase, balance,
 * return, payment and report keeps pointing at it exactly as before.
 *
 * There is deliberately NO global scope here. Retention is the default: unless a
 * caller explicitly asks for active()/archived(), it sees archived parties too,
 * which is what every history/settlement path requires.
 *
 * Eligibility is enforced in TWO layers, and a Category-A write path needs BOTH:
 *
 *   1. activeExistsRule()  — validation. Produces the friendly, field-level
 *      error (422 for JSON, redirect-with-errors for web) before any work starts.
 *      On its own this is a TOCTOU hole: the party can be archived between
 *      validation and the write.
 *
 *   2. lockActiveOrFail()  — authoritative. Runs INSIDE the same transaction
 *      that creates the commitment and takes a FOR UPDATE row lock, so it
 *      serialises against archive()/reactivate(), which lock the same row.
 *      Either the commitment locks first (and completes, archive waits), or the
 *      archive locks first (and the commitment then sees is_active = false and
 *      aborts with nothing written).
 *
 * Search/autocomplete endpoints only ever need layer 1's active() filter — they
 * create no commitment, so they need no lock.
 */
trait ArchivableParty
{
    /** Parties available for new commercial commitments. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    /** Parties withdrawn from new commitments but fully retained for history. */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS FALSE');
    }

    /**
     * Back-compat alias. Vendor shipped with inactive() and callers such as
     * Api\Mobile\VendorController's ?status=inactive filter still use it.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $this->scopeArchived($query);
    }

    public function isArchived(): bool
    {
        return ! $this->is_active;
    }

    /**
     * Human noun used in eligibility messages ("customer", "vendor").
     */
    public static function partyLabel(): string
    {
        return Str::lower(class_basename(static::class));
    }

    public static function archivedMessage(): string
    {
        $label = static::partyLabel();

        return "This {$label} is archived. Reactivate the {$label} before creating a new transaction.";
    }

    /**
     * LAYER 1 — validation rule for any new-commitment `*_id` field. Drops into
     * a rules array exactly where Rule::exists() used to sit, so JSON callers
     * keep their 422 contract and web callers keep field-level errors.
     *
     * It is a closure rule rather than Rule::exists() for one reason: an exists
     * rule can only say "invalid", while the whole point of Part 3 is telling
     * the user WHY — and telling them in the same words lockActiveOrFail() uses,
     * so the two layers never contradict each other.
     */
    public static function activeExistsRule(int $shopId): Closure
    {
        return static::activeOrCurrentExistsRule($shopId, null);
    }

    /**
     * LAYER 1, edit variant. An EXISTING record whose party was archived after it
     * was created must stay editable and settleable — archiving withdraws a party
     * from NEW commitments, it does not freeze the obligations already on the
     * books. So the party already linked to the record stays acceptable, while
     * switching to any other archived party is still rejected.
     */
    public static function activeOrCurrentExistsRule(int $shopId, ?int $currentId): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($shopId, $currentId): void {
            // 'required'/'nullable' owns emptiness; this rule only judges a value.
            if ($value === null || $value === '' || $value === []) {
                return;
            }

            $party = static::withoutTenant()
                ->where('shop_id', $shopId)
                ->whereKey($value)
                ->first();

            // A cross-shop or missing id is "not found" — never a status
            // disclosure about another tenant's records.
            if (! $party) {
                $fail('The selected ' . static::partyLabel() . ' was not found.');

                return;
            }

            if ($party->isArchived() && (int) $value !== $currentId) {
                $fail(static::archivedMessage());
            }
        };
    }

    /**
     * LAYER 2 — authoritative, race-safe check. MUST be called inside the
     * transaction that persists the commitment.
     *
     * Returns null when $id is null (an optional party, e.g. a stock purchase
     * with no vendor selected). Throws ValidationException — never a raw 500 —
     * so the caller's existing web/JSON error contract is preserved and the
     * surrounding transaction rolls back with no partial write.
     *
     * withoutTenant() + an explicit shop_id is deliberate, not a leak: it keeps
     * the check correct in console/job contexts where the tenant global scope is
     * not bound, while the explicit shop_id still enforces the tenant boundary.
     * A cross-shop or missing id is reported as "not found" so no other tenant's
     * archived/active status is ever disclosed.
     */
    public static function lockActiveOrFail(int $shopId, ?int $id, string $field, ?int $allowArchivedId = null): ?static
    {
        if ($id === null) {
            return null;
        }

        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
            throw new LogicException(static::class . '::lockActiveOrFail() must run inside a transaction; a row lock taken outside one cannot serialise against archive().');
        }

        $party = static::withoutTenant()
            ->where('shop_id', $shopId)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $party) {
            throw ValidationException::withMessages([
                $field => 'The selected ' . static::partyLabel() . ' was not found.',
            ]);
        }

        // $allowArchivedId is the party ALREADY linked to the record being
        // updated: editing or settling an existing obligation must keep working
        // after its party is archived (see activeOrCurrentExistsRule).
        if ($party->isArchived() && $id !== $allowArchivedId) {
            throw ValidationException::withMessages([
                $field => static::archivedMessage(),
            ]);
        }

        return $party;
    }
}
