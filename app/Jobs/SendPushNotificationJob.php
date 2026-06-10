<?php

namespace App\Jobs;

use App\Services\Mobile\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Off-thread push delivery. Resolves the shop's live push tokens and hands them
 * to the Expo sender. Shop-scoped by construction (shop_id is a hard filter
 * inside pushTokensForShop), so it is safe to run with no TenantContext bound.
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<string,mixed>  $data
     * @param  int[]|null  $userIds
     */
    public function __construct(
        public int $shopId,
        public string $title,
        public string $body,
        public array $data = [],
        public ?array $userIds = null,
    ) {}

    public function handle(PushNotificationService $push): void
    {
        $tokens = $push->pushTokensForShop($this->shopId, $this->userIds);

        if ($tokens->isEmpty()) {
            return;
        }

        $push->deliver($tokens, $this->title, $this->body, $this->data);
    }
}
