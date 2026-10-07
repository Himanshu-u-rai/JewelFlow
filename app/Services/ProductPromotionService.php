<?php

namespace App\Services;

use App\Models\User;
use App\Support\Realm;
use App\Support\ShopEdition;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform metadata ONLY. Never consulted for authentication, tenancy, billing or
 * permissions. Cross-shop reads below are restricted to exact, approved owners;
 * no operational model relationships or contact-detail matching are used.
 */
class ProductPromotionService
{
    public const CAMPAIGN = 'other-product-introduction-v1';

    /** Transaction-scoped, ordered owner locks also cover proofs not inserted yet. */
    public static function lockOwners(array $ids): void
    {
        sort($ids, SORT_NUMERIC);
        foreach (array_unique($ids) as $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['product-promotion-owner:'.$id]);
        }
    }

    /** Caller holds owner locks in the same transaction as its security write. */
    public static function invalidateOwners(array $ids): void
    {
        if (! $ids) {
            return;
        }
        DB::table('product_recognition_requests')->whereNull('consumed_at')->where(function ($query) use ($ids) {
            $query->whereIn('source_user_id', $ids)->orWhereIn('target_user_id', $ids);
        })->update(['consumed_at' => now(), 'updated_at' => now()]);
        DB::table('product_recognitions')->whereNull('revoked_at')->where(function ($query) use ($ids) {
            $query->whereIn('erp_user_id', $ids)->orWhereIn('dhiran_user_id', $ids);
        })->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && $user->isShopOwner()
            && $user->realm === Realm::current($request)
            && TenantContext::get() !== null
            && (int) $user->shop_id === TenantContext::get(), 403);
        abort_unless($this->identity($user->id, $user->shop_id, $user->realm), 403);

        return $user;
    }

    public function target(User $user): string
    {
        return $user->realm === Realm::ERP ? Realm::DHIRAN : Realm::ERP;
    }

    public function routePrefix(User $user): string
    {
        return $user->realm === Realm::DHIRAN ? 'dhiran.product-preferences' : 'product-preferences';
    }

    /** No production fallback in staging. Explicitly configured URLs are trusted deployment settings. */
    public function targetUrl(Request $request, User $user): ?string
    {
        if ($this->target($user) === Realm::DHIRAN) {
            $url = Realm::dhiranRegisterUrl($request);
        } else {
            $url = config('platform.cross_promotion.erp_register_url');
            if ($url === null && app()->environment(['production', 'local'])) {
                $host = preg_replace('/^dhiran\./i', '', $request->getHost());
                $port = in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort();
                $url = $request->getScheme().'://'.$host.$port.'/register';
            }
        }
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (isset($parts['user']) || isset($parts['pass'])
            || ! in_array($parts['scheme'] ?? '', app()->environment('local') ? ['http', 'https'] : ['https'], true)) {
            return null;
        }

        return $url;
    }

    /** The database, not browser storage, claims the single introduction. */
    public function claimIntroduction(Request $request): ?string
    {
        try {
            // Include optional reads in the savepoint: catching a PostgreSQL
            // error without rolling it back poisons any enclosing transaction.
            return DB::transaction(function () use ($request) {
                if (! config('platform.cross_promotion.enabled')) {
                    return null;
                }
                $user = $request->user();
                if (! $user) {
                    return null;
                }
                self::lockOwners([$user->id]);
                $user = $this->owner($request);
                $url = $this->targetUrl($request, $user);
                if (! $url || $this->hasLegacyEdition($user) || $this->recognitions($user)->isNotEmpty()) {
                    return null;
                }
                $preference = $this->preference($user, true);
                if ($preference->choice !== null) {
                    return null;
                }
                $claimed = DB::table('product_promotion_exposures')->insertOrIgnore([
                    'preference_id' => $preference->id, 'campaign' => self::CAMPAIGN, 'created_at' => now(),
                ]);

                return $claimed === 1 ? $url : null;
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return null; // Staff, missing tenant or ambiguous identity: no marketing.
        } catch (\Throwable $e) {
            // A failed optional lookup must not break the dashboard or show an ad.
            Log::warning('Product promotion hidden: lookup unavailable.', ['exception' => $e::class]);

            return null;
        }
    }

    private function hasLegacyEdition(User $user): bool
    {
        $shop = $user->shop;

        return $this->target($user) === Realm::DHIRAN
            ? $shop->hasEdition(ShopEdition::DHIRAN)
            : ($shop->hasEdition(ShopEdition::RETAILER) || $shop->hasEdition(ShopEdition::MANUFACTURER));
    }

    private function key(User $user): array
    {
        return ['environment' => app()->environment(), 'realm' => $user->realm,
            'shop_id' => $user->shop_id, 'user_id' => $user->id, 'target' => $this->target($user)];
    }

    public function preference(User $user, bool $lock = false): object
    {
        DB::table('product_promotion_preferences')->insertOrIgnore($this->key($user) + ['created_at' => now(), 'updated_at' => now()]);
        $query = DB::table('product_promotion_preferences')->where($this->key($user));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function choose(User $user, string $choice): void
    {
        abort_unless(in_array($choice, ['already_use', 'opt_out'], true), 422);
        DB::transaction(function () use ($user, $choice) {
            self::lockOwners([$user->id]);
            $preference = $this->preference($user, true);
            DB::table('product_promotion_preferences')->where('id', $preference->id)->update(['choice' => $choice, 'updated_at' => now()]);
        });
    }

    /** Exact current owner metadata; deliberately no tenant scope bypass on business models. */
    private function identity(int $userId, int $shopId, string $realm): ?object
    {
        return DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')
            ->join('shops as s', 's.id', '=', 'u.shop_id')
            ->where('u.id', $userId)->where('u.shop_id', $shopId)->where('u.realm', $realm)
            ->whereRaw('"u"."is_active" IS TRUE')->where('r.name', 'owner')->whereColumn('r.shop_id', 'u.shop_id')
            ->first(['u.id', 'u.shop_id', 'u.realm', 'u.role_id', 'u.password', 'u.created_at']);
    }

    private function proof(object $identity): string
    {
        return hash_hmac('sha256', json_encode((array) $identity, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function proofMatches(int $userId, int $shopId, string $realm, string $proof): bool
    {
        $identity = $this->identity($userId, $shopId, $realm);

        return $identity && hash_equals($proof, $this->proof($identity));
    }

    private function confirmPassword(User $user, string $password): object
    {
        // Check this exact authenticated user, never an email lookup across realms.
        // The proof must use the SAME identity image whose password was checked.
        $identity = $this->identity($user->id, $user->shop_id, $user->realm);
        abort_unless($identity, 403);
        if (! Hash::check($password, $identity->password)) {
            throw ValidationException::withMessages(['password' => 'Your current password was not accepted.']);
        }

        return $identity;
    }

    public function start(User $user, string $password): string
    {
        $code = strtoupper(bin2hex(random_bytes(20)));
        DB::transaction(function () use ($user, $password, $code) {
            self::lockOwners([$user->id]);
            $identity = $this->confirmPassword($user, $password);
            // Serialize starts from this owner; only the newest request stays usable.
            $this->preference($user, true);
            $this->requests($user)->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('product_recognition_requests')->insert([
                'id' => (string) Str::uuid(), 'environment' => app()->environment(), 'source_realm' => $user->realm,
                'source_user_id' => $user->id, 'source_shop_id' => $user->shop_id,
                'source_proof' => $this->proof($identity), 'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $code;
    }

    public function requests(User $user)
    {
        return DB::table('product_recognition_requests')->where('environment', app()->environment())
            ->where('source_realm', $user->realm)->where('source_user_id', $user->id)->where('source_shop_id', $user->shop_id);
    }

    public function incomingRequests(User $user)
    {
        return DB::table('product_recognition_requests')->where('environment', app()->environment())
            ->where('source_realm', $this->target($user))->where('target_user_id', $user->id)->where('target_shop_id', $user->shop_id);
    }

    public function cancel(User $user, string $id): void
    {
        $query = DB::table('product_recognition_requests')->where('environment', app()->environment())
            ->where('id', $id)->whereNull('consumed_at')->where(function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('source_realm', $user->realm)->where('source_user_id', $user->id)->where('source_shop_id', $user->shop_id);
                })->orWhere(function ($q) use ($user) {
                    $q->where('source_realm', $this->target($user))->where('target_user_id', $user->id)->where('target_shop_id', $user->shop_id);
                });
            });
        abort_unless($query->update(['consumed_at' => now(), 'updated_at' => now()]) === 1, 404);
    }

    public function approve(User $user, string $password, string $code): void
    {
        DB::transaction(function () use ($user, $password, $code) {
            $query = DB::table('product_recognition_requests')->where('environment', app()->environment())
                ->where('source_realm', $this->target($user))->where('code_hash', hash('sha256', strtoupper(trim($code))))
                ->whereNull('consumed_at')->whereNull('target_user_id');
            $candidate = (clone $query)->first();
            if (! $candidate) {
                throw ValidationException::withMessages(['code' => 'This code is unavailable. Ask the initiating owner for a new code.']);
            }
            self::lockOwners([$user->id, $candidate->source_user_id]);
            $identity = $this->confirmPassword($user, $password);
            $row = $query->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $row || now()->greaterThanOrEqualTo($row->expires_at)
                || (int) $row->source_shop_id === (int) $user->shop_id
                || ! $this->proofMatches($row->source_user_id, $row->source_shop_id, $row->source_realm, $row->source_proof)) {
                throw ValidationException::withMessages(['code' => 'This code is unavailable. Ask the initiating owner for a new code.']);
            }
            DB::table('product_recognition_requests')->where('id', $row->id)->update([
                'target_user_id' => $user->id, 'target_shop_id' => $user->shop_id,
                'target_proof' => $this->proof($identity), 'target_approved_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function finish(User $user, string $password, string $id): void
    {
        DB::transaction(function () use ($user, $password, $id) {
            $candidate = $this->requests($user)->where('id', $id)->whereNotNull('target_user_id')->first();
            abort_unless($candidate, 409, 'This request is no longer available.');
            self::lockOwners([$user->id, $candidate->target_user_id]);
            $this->confirmPassword($user, $password);
            $row = $this->requests($user)->where('id', $id)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->whereNotNull('target_user_id')->lockForUpdate()->first();
            abort_unless($row && now()->lessThan($row->expires_at), 409, 'This request is no longer available.');
            $target = $this->target($user);
            abort_unless($this->proofMatches($row->source_user_id, $row->source_shop_id, $user->realm, $row->source_proof)
                && $this->proofMatches($row->target_user_id, $row->target_shop_id, $target, $row->target_proof), 409, 'Ownership changed. Start a new request.');
            $pair = ['environment' => app()->environment(),
                $user->realm.'_user_id' => $row->source_user_id, $user->realm.'_shop_id' => $row->source_shop_id,
                $target.'_user_id' => $row->target_user_id, $target.'_shop_id' => $row->target_shop_id];
            $values = [$user->realm.'_proof' => $row->source_proof, $target.'_proof' => $row->target_proof,
                $user->realm.'_approved_at' => now(), $target.'_approved_at' => $row->target_approved_at,
                'request_id' => $row->id, 'revoked_at' => null, 'updated_at' => now()];
            // Canonical ERP/Dhiran columns + unique pair make opposite-direction approvals converge.
            DB::table('product_recognitions')->upsert(
                [$pair + $values + ['id' => (string) Str::uuid(), 'created_at' => now()]],
                ['environment', 'erp_user_id', 'erp_shop_id', 'dhiran_user_id', 'dhiran_shop_id'], array_keys($values)
            );
            DB::table('product_recognition_requests')->where('id', $id)->update(['consumed_at' => now(), 'updated_at' => now()]);
        });
    }

    public function recognitions(User $user)
    {
        $realm = $user->realm;

        return DB::table('product_recognitions')->where('environment', app()->environment())
            ->where($realm.'_user_id', $user->id)->where($realm.'_shop_id', $user->shop_id)->whereNull('revoked_at')
            ->get()->filter(function ($row) {
                foreach ([Realm::ERP, Realm::DHIRAN] as $side) {
                    if (! $this->proofMatches($row->{$side.'_user_id'}, $row->{$side.'_shop_id'}, $side, $row->{$side.'_proof'})) {
                        // A new confirmation may replace this snapshot while its
                        // proofs are checked. Never revoke that newer consent.
                        DB::table('product_recognitions')->where('id', $row->id)->where('request_id', $row->request_id)
                            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

                        return false;
                    }
                }

                return true;
            })->map(fn ($row) => (object) ['id' => $row->id, 'created_at' => $row->created_at,
                'confirmed_at' => max($row->erp_approved_at, $row->dhiran_approved_at),
                'business' => DB::table('shops')->where('id', $row->{$this->target($user).'_shop_id'})->value('name')]);
    }

    public function revoke(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $query = DB::table('product_recognitions')->where('environment', app()->environment())
                ->where($user->realm.'_user_id', $user->id)->where($user->realm.'_shop_id', $user->shop_id)
                ->where('id', $id)->whereNull('revoked_at');
            $link = (clone $query)->first();
            abort_unless($link, 404);
            self::lockOwners([$link->erp_user_id, $link->dhiran_user_id]);
            $link = $query->first();
            abort_unless($link, 404);
            // Revoke outstanding consent too, so an old approved request cannot
            // silently re-create this pair. Request rows precede link rows in lock order.
            DB::table('product_recognition_requests')->where('environment', app()->environment())
                ->whereNull('consumed_at')->where(function ($query) use ($link) {
                    foreach ([Realm::ERP, Realm::DHIRAN] as $source) {
                        $target = $source === Realm::ERP ? Realm::DHIRAN : Realm::ERP;
                        $query->orWhere(function ($q) use ($link, $source, $target) {
                            $q->where('source_realm', $source)->where('source_user_id', $link->{$source.'_user_id'})
                                ->where('source_shop_id', $link->{$source.'_shop_id'})
                                ->where('target_user_id', $link->{$target.'_user_id'})->where('target_shop_id', $link->{$target.'_shop_id'});
                        });
                    }
                })->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('product_recognitions')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        }, 3);
    }
}
