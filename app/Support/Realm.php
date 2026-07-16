<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer-facing product realm ('erp' | 'dhiran').
 *
 * A realm is which product an account/request belongs to. It is resolved from
 * the request host: the `dhiran.*` subdomain is the Dhiran realm; everything else
 * is the ERP realm (the default). One account belongs to exactly one realm.
 *
 * Phase 0: this helper is provided for the upcoming auth/onboarding work but is
 * NOT yet wired into login/register/password-reset. It is host-detection +
 * realm-scoped validation rules, kept simple and testable.
 */
final class Realm
{
    public const ERP = User::REALM_ERP;       // 'erp'
    public const DHIRAN = User::REALM_DHIRAN;  // 'dhiran'

    /** True for a valid realm string. */
    public static function isValid(?string $realm): bool
    {
        return in_array($realm, User::REALMS, true);
    }

    /**
     * Resolve the realm from a host string. A host starting with "dhiran." (e.g.
     * dhiran.jewelflows.com, dhiran.localhost) is the Dhiran realm; anything else
     * is ERP.
     */
    public static function fromHost(?string $host): string
    {
        $host = strtolower((string) $host);

        return str_starts_with($host, 'dhiran.') ? self::DHIRAN : self::ERP;
    }

    /** Resolve the realm for the current (or given) request, from its host. */
    public static function current(?Request $request = null): string
    {
        $request ??= request();

        return self::fromHost($request?->getHost());
    }

    /** The realm a user account belongs to (defaults to ERP if somehow unset). */
    public static function of(User $user): string
    {
        return self::isValid($user->realm) ? $user->realm : self::ERP;
    }

    /**
     * Environment-safe Dhiran "register" URL for cross-promotion, or null when the
     * CTA must be hidden. This is the single source of truth for the promo link.
     *
     * Policy:
     *   1. DHIRAN_REGISTER_URL set to a non-empty value  → use it verbatim (any env).
     *   2. DHIRAN_REGISTER_URL set but EMPTY             → explicitly disabled → null.
     *      (An empty/disabled config must NEVER fall back to a derived/production URL.)
     *   3. No override, environment = production          → derive from the request
     *      host (jewelflows.com → https://dhiran.jewelflows.com/register).
     *   4. No override, environment = local               → derive from the local host
     *      (dhiran.<localhost>) — a Dhiran realm resolves locally.
     *   5. No override, any other environment (staging …) → null. There is no Dhiran
     *      DNS/cert outside production, so we hide the CTA rather than derive a broken
     *      host or leak users to production.
     *
     * $environment defaults to the running app environment; it is injectable for tests.
     */
    public static function dhiranRegisterUrl(?Request $request = null, ?string $environment = null): ?string
    {
        $override = config('platform.cross_promotion.dhiran_register_url');
        if ($override !== null) {
            // Explicitly configured — a non-empty value wins; an empty value disables
            // the CTA and must not fall through to host derivation.
            $override = is_string($override) ? trim($override) : '';

            return $override !== '' ? $override : null;
        }

        $environment ??= app()->environment();
        if (! in_array($environment, ['production', 'local'], true)) {
            return null;
        }

        $request ??= request();
        $host = strtolower((string) ($request?->getHost() ?? ''));
        if ($host === '') {
            return null;
        }

        // Already a Dhiran host → use as-is; otherwise prefix the dhiran. subdomain.
        if (! str_starts_with($host, 'dhiran.')) {
            $host = 'dhiran.' . $host;
        }

        $scheme = $request?->getScheme() ?: 'https';

        return "{$scheme}://{$host}/register";
    }

    /**
     * Validation rule for registering a UNIQUE mobile_number WITHIN a realm —
     * mirrors the (mobile_number, realm) composite unique index. The same phone
     * may exist once per realm, but never twice within one realm.
     *
     * Usage (Phase 1, when wiring register):
     *   'mobile_number' => ['required', Realm::uniqueMobileRule($realm)]
     */
    public static function uniqueMobileRule(string $realm, ?int $ignoreUserId = null)
    {
        $rule = Rule::unique('users', 'mobile_number')->where(fn ($q) => $q->where('realm', $realm));

        return $ignoreUserId ? $rule->ignore($ignoreUserId) : $rule;
    }

    /**
     * Find the single user matching a login identity WITHIN a realm. Login must
     * only ever resolve an account in the current realm (never cross-realm), so
     * the same phone in the other realm is invisible here.
     */
    public static function findUserByMobile(string $mobile, string $realm): ?User
    {
        return User::query()->where('mobile_number', $mobile)->where('realm', $realm)->first();
    }
}
