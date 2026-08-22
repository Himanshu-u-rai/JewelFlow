<?php

namespace App\Services\Historical;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Suggestion-only customer matching for historical entries. Nothing here ever
 * sets `customer_id` — it only returns candidates for a human to accept.
 *
 *   mobile — `(shop_id, mobile)` is a unique index, so an exact normalized
 *            match is at most one customer: the strongest signal available.
 *   gstin  — not unique. More than one match is reported "ambiguous" and
 *            never resolved by picking one.
 *   name   — weakest signal. Exact normalized equality only, no LIKE/fuzzy
 *            scan, bounded result count, never preselected by any caller.
 *
 * withoutTenant() + an explicit shop_id (not the ambient TenantContext scope)
 * is deliberate: every query stays correct and auditable regardless of what
 * context calls it, matching the pattern ArchivableParty already uses.
 *
 * Archived (is_active = false) customers never appear here — ArchivableParty
 * already means "not eligible for new commitments," and a fresh suggestion is
 * exactly that. A customer already linked before archiving is unaffected;
 * this class is never consulted for an existing link, only for new ones.
 */
class HistoricalCustomerMatcher
{
    private const NAME_LIMIT = 5;

    /** @return array{status: string, customers: Collection<int, Customer>} */
    public function byMobile(int $shopId, ?string $mobile): array
    {
        $normalized = self::normalizeMobile($mobile);
        if ($normalized === null) {
            return ['status' => 'none', 'customers' => collect()];
        }

        $customers = Customer::withoutTenant()
            ->active()
            ->where('shop_id', $shopId)
            ->where('mobile', $normalized)
            ->get();

        return [
            'status'    => $customers->isEmpty() ? 'none' : 'match',
            'customers' => $customers,
        ];
    }

    /** @return array{status: string, customers: Collection<int, Customer>} */
    public function byGstin(int $shopId, ?string $gstin): array
    {
        $normalized = self::normalizeGstin($gstin);
        if ($normalized === null) {
            return ['status' => 'none', 'customers' => collect()];
        }

        $customers = Customer::withoutTenant()
            ->active()
            ->where('shop_id', $shopId)
            ->whereRaw('UPPER(TRIM(gstin)) = ?', [$normalized])
            ->get();

        return [
            'status'    => match (true) {
                $customers->isEmpty()   => 'none',
                $customers->count() > 1 => 'ambiguous',
                default                 => 'match',
            },
            'customers' => $customers,
        ];
    }

    /** @return array{status: string, customers: Collection<int, Customer>} */
    public function byName(int $shopId, ?string $name): array
    {
        $normalized = self::normalizeName($name);
        if ($normalized === null) {
            return ['status' => 'none', 'customers' => collect()];
        }

        $customers = Customer::withoutTenant()
            ->active()
            ->where('shop_id', $shopId)
            ->whereRaw("UPPER(TRIM(CONCAT_WS(' ', first_name, last_name))) = ?", [$normalized])
            ->limit(self::NAME_LIMIT)
            ->get();

        return [
            'status'    => $customers->isEmpty() ? 'none' : 'match',
            'customers' => $customers,
        ];
    }

    /** @return array{mobile: array, gstin: array, name: array} */
    public function suggest(int $shopId, ?string $name, ?string $mobile, ?string $gstin): array
    {
        return [
            'mobile' => $this->byMobile($shopId, $mobile),
            'gstin'  => $this->byGstin($shopId, $gstin),
            'name'   => $this->byName($shopId, $name),
        ];
    }

    /**
     * Delegates to the model so the read side of matching and the write side of
     * Customer::findOrCreateByMobile() can never disagree about what a mobile
     * number looks like — which is exactly how Quick Bill customers became
     * invisible to this matcher.
     */
    public static function normalizeMobile(?string $mobile): ?string
    {
        return Customer::normalizeMobile($mobile);
    }

    public static function normalizeGstin(?string $gstin): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim((string) $gstin)) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    public static function normalizeName(?string $name): ?string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $name) ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
