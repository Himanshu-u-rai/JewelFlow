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
    /**
     * Resolve the capability flags for the given shop/user pair.
     */
    public function resolve(Shop $shop, User $user): CapabilitiesData
    {
        $planFeatures = $this->planFeatures($shop);

        return new CapabilitiesData(
            items:        $this->gate($planFeatures, 'inventory', $user, 'inventory.view'),
            stock:        $this->gate($planFeatures, 'inventory', $user, 'inventory.view'),
            customers:    $this->gate($planFeatures, 'customers', $user, 'customers.view'),
            suppliers:    $this->gate($planFeatures, 'vendors', $user, null, true),
            purchases:    $this->gate($planFeatures, null, $user, null, true),
            pos:          $this->gate($planFeatures, 'pos', $user, 'sales.pos'),
            quick_bill:   $this->gate($planFeatures, null, $user, 'sales.pos', true),
            invoice:      $this->gate($planFeatures, 'invoices', $user, 'invoices.view'),
            repairs:      $this->gate($planFeatures, 'repairs', $user, 'repairs.view'),
            expenses:     $this->gate($planFeatures, null, $user, 'cash.view', true),
            catalog:      $this->catalogGate($planFeatures, $user),
            dashboard:    $this->gate($planFeatures, null, $user, null, true),
            scanner:      $this->gate($planFeatures, null, $user, null, true),
            schemes:      $this->gate($planFeatures, 'schemes', $user, null),
            loyalty:      $this->gate($planFeatures, 'loyalty', $user, null),
            installments: $this->gate($planFeatures, 'installments', $user, null),
            cashbook:     $this->gate($planFeatures, null, $user, 'cash.view', true),
        );
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
     * Apply the plan-feature AND user-permission gate for a single capability.
     *
     * @param array<string, mixed> $planFeatures
     * @param string|null $planFeature    Plan feature key; null means "always-on" (unless $defaultWhenMissing is false).
     * @param string|null $permission     RBAC permission name; null means no permission gate.
     * @param bool $defaultWhenMissing    If the plan feature key is absent, default to this (true = available to all plans).
     */
    private function gate(
        array $planFeatures,
        ?string $planFeature,
        User $user,
        ?string $permission,
        bool $defaultWhenMissing = true
    ): bool {
        $planOk = $this->planAllows($planFeatures, $planFeature, $defaultWhenMissing);
        if (! $planOk) {
            return false;
        }

        if ($permission === null) {
            return true;
        }

        return $this->userAllows($user, $permission);
    }

    /**
     * Catalog is enabled if either whatsapp_catalog OR public_catalog plan
     * feature is on. If neither key is present at all, default to true
     * (always-on per spec).
     */
    private function catalogGate(array $planFeatures, User $user): bool
    {
        $hasAny = array_key_exists('whatsapp_catalog', $planFeatures)
            || array_key_exists('public_catalog', $planFeatures);

        if (! $hasAny) {
            return true; // always-on default
        }

        return (bool) ($planFeatures['whatsapp_catalog'] ?? false)
            || (bool) ($planFeatures['public_catalog'] ?? false);
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
