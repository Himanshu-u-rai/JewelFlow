<?php

namespace App\Jobs;

use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBulkShopEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $shopId,
        public readonly string $subject,
        public readonly string $body,
        public readonly int $adminId,
    ) {}

    public function handle(): void
    {
        $shop  = Shop::find($this->shopId);
        $email = $shop?->owner_email ?? null;

        if (! $email) {
            Log::info('SendBulkShopEmail: no owner_email on shop, skipping', [
                'shop_id' => $this->shopId,
            ]);
            return;
        }

        Mail::raw($this->body, function ($message) use ($email) {
            $message->to($email)->subject($this->subject);
        });

        Log::info('SendBulkShopEmail: sent', [
            'shop_id'  => $this->shopId,
            'email'    => $email,
            'admin_id' => $this->adminId,
        ]);
    }
}
