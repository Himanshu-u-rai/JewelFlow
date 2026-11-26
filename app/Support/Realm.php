<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer-facing product realm.
 *
 * Every account and request belongs to the ERP realm. The realm column, the
 * (mobile_number, realm) unique index and the realm-keyed password reset tokens
 * stay in place, so login identity and uniqueness rules are unchanged.
 */
final class Realm
{
    public const ERP = User::REALM_ERP;       // 'erp'

    /** True for a valid realm string. */
    public static function isValid(?string $realm): bool
    {
        return in_array($realm, User::REALMS, true);
    }

    /** Resolve the realm for the current (or given) request. */
    public static function current(?Request $request = null): string
    {
        return self::ERP;
    }

    /** The realm a user account belongs to (defaults to ERP if somehow unset). */
    public static function of(User $user): string
    {
        return self::isValid($user->realm) ? $user->realm : self::ERP;
    }

    /**
     * Validation rule for registering a UNIQUE mobile_number WITHIN a realm —
     * mirrors the (mobile_number, realm) composite unique index.
     *
     *   'mobile_number' => ['required', Realm::uniqueMobileRule($realm)]
     */
    public static function uniqueMobileRule(string $realm, ?int $ignoreUserId = null)
    {
        $rule = Rule::unique('users', 'mobile_number')->where(fn ($q) => $q->where('realm', $realm));

        return $ignoreUserId ? $rule->ignore($ignoreUserId) : $rule;
    }

    /**
     * Find the single user matching a login identity WITHIN a realm. Login must
     * only ever resolve an account in the current realm.
     */
    public static function findUserByMobile(string $mobile, string $realm): ?User
    {
        return User::query()->where('mobile_number', $mobile)->where('realm', $realm)->first();
    }
}
