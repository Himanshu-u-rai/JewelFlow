<?php

namespace App\Services\Mobile;

use App\Jobs\SendPushNotificationJob;
use App\Models\MobileDeviceSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push delivery for the mobile app (Expo provider).
 *
 * Security model:
 *  - Token resolution is ALWAYS hard shop-scoped (shop_id WHERE) and uses the
 *    `pushable` scope (live session + token present). A push physically cannot
 *    reach another tenant's device.
 *  - Delivery is fire-and-forget from a queued job; a push failure never throws
 *    into the business operation that triggered it.
 *  - Payloads should carry deep-link ids only — never amounts or PII. The app
 *    fetches detail back over the authenticated API after the user taps.
 */
class PushNotificationService
{
    public const PROVIDER_EXPO = 'expo';

    /**
     * Validate an Expo push token shape before it is ever stored.
     * Expo tokens look like "ExponentPushToken[xxxx]" or "ExpoPushToken[xxxx]".
     */
    public function isValidExpoToken(string $token): bool
    {
        return (bool) preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', trim($token));
    }

    /**
     * Live, push-capable tokens for a shop, optionally restricted to specific
     * users (targeted sends, e.g. approvals -> owners/managers only).
     *
     * Explicitly shop-scoped + withoutTenant(), so it is safe to call from a
     * queued job where no TenantContext is bound. Cross-tenant leakage is
     * structurally impossible: shop_id is a hard WHERE.
     *
     * @param  int[]|null  $userIds
     * @return Collection<int,string>
     */
    public function pushTokensForShop(int $shopId, ?array $userIds = null): Collection
    {
        return MobileDeviceSession::withoutTenant()
            ->where('shop_id', $shopId)
            ->pushable()
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->pluck('push_token')
            ->unique()
            ->values();
    }

    /**
     * Queue a push to every live device in a shop (optionally a subset of users).
     * Non-blocking by contract — never call deliver() from a request thread.
     *
     * @param  array<string,mixed>  $data   deep-link payload (ids only, no PII)
     * @param  int[]|null  $userIds
     */
    public function queueToShop(int $shopId, string $title, string $body, array $data = [], ?array $userIds = null): void
    {
        SendPushNotificationJob::dispatch($shopId, $title, $body, $data, $userIds);
    }

    /**
     * Deliver to the Expo push service. Called from the queued job. Batched at
     * 100 (Expo's per-request limit). Failures are logged, never thrown.
     *
     * @param  iterable<int,string>  $tokens
     * @param  array<string,mixed>  $data
     */
    public function deliver(iterable $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = collect($tokens)
            ->filter(fn ($t) => is_string($t) && $this->isValidExpoToken($t))
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $url         = config('services.expo.push_url', 'https://exp.host/--/api/v2/push/send');
        $accessToken = config('services.expo.access_token');

        foreach ($tokens->chunk(100) as $batch) {
            $messages = $batch->map(fn (string $to) => [
                'to'       => $to,
                'title'    => $title,
                'body'     => $body,
                'data'     => $data,
                'sound'    => 'default',
                'priority' => 'high',
            ])->values()->all();

            try {
                $request = Http::asJson()->acceptJson()->timeout(10);
                if (! empty($accessToken)) {
                    $request = $request->withToken($accessToken);
                }

                $response = $request->post($url, $messages);

                if ($response->failed()) {
                    Log::warning('Expo push delivery failed', [
                        'status' => $response->status(),
                        'count'  => count($messages),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Expo push delivery threw: ' . $e->getMessage(), [
                    'count' => count($messages),
                ]);
            }
        }
    }
}
