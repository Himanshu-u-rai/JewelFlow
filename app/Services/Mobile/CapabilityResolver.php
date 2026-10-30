<?php

namespace App\Services\Mobile;

use App\Data\Mobile\CapabilitiesData;
use App\Models\Shop;
use App\Models\User;

/**
 * Derives the per-tenant capability flags exposed over the mobile bootstrap
 * payload by intersecting the active subscription plan features with the
 * authenticated user's RBAC permissions.
 *
 * Pure and read-only: performs no writes and triggers no side effects.
 */
class CapabilityResolver
{
    /** Why each capability resolved off, keyed by capability (filled by resolve()). */
    private array $reasons = [];

    /** The reasons behind the last resolve(): {key: {reason, permission?}}. */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * Resolve the capability flags for the given shop/user pair. Each flag asks
     * for the permission the web's twin route asks for (web leads), and every
     * flag that comes out off records why.
     */
    public function resolve(Shop $shop, User $user): CapabilitiesData
    {
        $this->reasons = [];
        $planFeatures = $this->planFeatures($shop);
        $gate = fn (string $key, ?string $planFeature, ?string $permission, bool $default = true) =>
            $this->flag($key, $planFeatures, $planFeature, $user, $permission, $default);

        // Owner-controlled per-shop toggle. Default true (no row / null = enabled).
        $quickBillEnabled = $shop->preferences?->quick_bill_enabled ?? true;
        $quickBill = fn (string $key, string $permission) => $quickBillEnabled
            ? $gate($key, null, $permission)
            : $this->off($key, ['reason' => 'disabled']);

        return new CapabilitiesData(
            items:        $gate('items', 'inventory', 'inventory.view'),
            stock:        $gate('stock', 'inventory', 'inventory.view'),
            customers:    $gate('customers', 'customers', 'customers.view'),
            suppliers:    $gate('suppliers', 'vendors', 'vendors.view'),
            purchases:    $gate('purchases', null, 'inventory.view'),
            pos:          $gate('pos', 'pos', 'sales.pos'),
            // The web: quick-bills list = sales.view, create = sales.create.
            quick_bill:   $quickBill('quick_bill', 'sales.view'),
            invoice:      $gate('invoice', 'invoices', 'sales.view'),
            repairs:      $gate('repairs', 'repairs', 'repairs.view'),
            expenses:     $gate('expenses', null, 'cash.view'),
            catalog:      $this->catalogGate($planFeatures, $user),
            // Money figures (revenue, profit, trends); rates and notices need nothing.
            dashboard:    $gate('dashboard', null, 'reports.view'),
            // The scanner looks tags up for stock or for a sale (the lookup-item gate:
            // inventory.view or sales.pos, as the web POS's tag lookup).
            scanner:      $user->can('lookup-item') ? true : $this->off('scanner', ['reason' => 'permission']),
            schemes:      $gate('schemes', 'schemes', null),
            loyalty:      $gate('loyalty', 'loyalty', null),
            installments: $gate('installments', 'installments', 'sales.view'),
            cashbook:        $this->cashbookGate('cashbook', $user, 'cash.view'),
            cashbook_create: $this->cashbookGate('cashbook_create', $user, 'cash.create'),
            items_create:    $gate('items_create', 'inventory', 'inventory.create'),
            returns_approve: $gate('returns_approve', null, 'returns.approve'),
            repairs_edit:    $gate('repairs_edit', 'repairs', 'repairs.edit'),
            suppliers_manage: $gate('suppliers_manage', 'vendors', 'vendors.manage'),
            devices:         $gate('devices', null, 'settings.view'),
            quick_bill_create: $quickBill('quick_bill_create', 'sales.create'),
            customers_create:  $gate('customers_create', 'customers', 'customers.create'),
            repairs_create:    $gate('repairs_create', 'repairs', 'repairs.create'),
            items_edit:        $gate('items_edit', 'inventory', 'inventory.edit'),
            pricing:           $gate('pricing', null, 'pricing.update'),
            invoice_payment:   $gate('invoice_payment', 'invoices', 'sales.create'),
        );
    }

    /** Plan, then permission; an off flag records which of the two said no. */
    private function flag(string $key, array $planFeatures, ?string $planFeature, User $user, ?string $permission, bool $default): bool
    {
        if (! $this->planAllows($planFeatures, $planFeature, $default)) {
            return $this->off($key, ['reason' => 'plan']);
        }
        if ($permission !== null && ! $this->userAllows($user, $permission)) {
            return $this->off($key, ['reason' => 'permission']); // never which one: staff are not told why
        }

        return true;
    }

    private function off(string $key, array $reason): bool
    {
        $this->reasons[$key] = $reason;

        return false;
    }


    /**
     * Load the active subscription plan features for a shop.
     *
     * @return array<string, mixed>
     */
    private function planFeatures(Shop $shop): array
    {
        $shop->loadMissing('subscription.plan');
        $features = $shop->subscription?->plan?->features;

        return is_array($features) ? $features : [];
    }

    /**
     * Mobile Cash Book capability gate — mirrors CashBookController::authorizeCashbook
     * exactly (single source of truth for the product rule). Only an owner or a
     * manager qualifies, and they must additionally hold the permission. A
     * cashier/staff member is denied even if their seeded role carries the
     * permission; an owner with the permission revoked is denied too. Plan-feature
     * independent — Cash Book is always-on per plan.
     */
    private function cashbookGate(string $key, User $user, string $permission): bool
    {
        if (! $user->isOwner() && ! $user->isManager()) {
            return $this->off($key, ['reason' => 'role']);
        }
        if (! $user->hasPermission($permission)) {
            return $this->off($key, ['reason' => 'permission']);
        }

        return true;
    }

    /**
     * Catalog is on the plan if either whatsapp_catalog OR public_catalog plan
     * feature is on (neither key present = always on), and the user needs
     * catalog.manage, as every web and app catalog route.
     */
    private function catalogGate(array $planFeatures, User $user): bool
    {
        $hasAny = array_key_exists('whatsapp_catalog', $planFeatures)
            || array_key_exists('public_catalog', $planFeatures);

        $planOk = ! $hasAny
            || (bool) ($planFeatures['whatsapp_catalog'] ?? false)
            || (bool) ($planFeatures['public_catalog'] ?? false);
        if (! $planOk) {
            return $this->off('catalog', ['reason' => 'plan']);
        }

        return $this->flag('catalog', $planFeatures, null, $user, 'catalog.manage', true);
    }

    /**
     * Plan gate: feature present and truthy, or absent and default=true.
     */
    private function planAllows(array $planFeatures, ?string $key, bool $defaultWhenMissing): bool
    {
        if ($key === null) {
            return $defaultWhenMissing;
        }

        if (! array_key_exists($key, $planFeatures)) {
            return $defaultWhenMissing;
        }

        return (bool) $planFeatures[$key];
    }

    /**
     * Permission gate: owners always pass; otherwise defer to the role's
     * permissions list. If the permission isn't defined in the system at all
     * (edge case), we don't block access.
     */
    private function userAllows(User $user, string $permission): bool
    {
        // Owners bypass all permission checks by design.
        if (method_exists($user, 'isOwner') && $user->isOwner()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
