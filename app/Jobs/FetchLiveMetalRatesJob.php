<?php

namespace App\Jobs;

use App\Models\MetalRate;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchLiveMetalRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            $url = (string) config('services.metal_rates.url', '');
            if ($url === '') {
                Log::warning('metal_rates_fetch_skipped', ['reason' => 'missing_url']);
                return;
            }

            $headers = [];
            $apiKey = (string) config('services.metal_rates.key', '');
            if ($apiKey !== '') {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                // GoldAPI expects this header; harmless for providers that ignore unknown headers.
                $headers['x-access-token'] = $apiKey;
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($url);

            if (!$response->ok()) {
                Log::warning('metal_rates_fetch_failed', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                return;
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                Log::warning('metal_rates_fetch_failed', ['reason' => 'invalid_json']);
                return;
            }

            $now = now();
            $rates = $this->normalizeRates($payload);

            foreach ($rates as $row) {
                MetalRate::record([
                    'shop_id' => null,
                    'metal_type' => $row['metal_type'],
                    'purity' => $row['purity'],
                    'rate_per_gram' => $row['rate_per_gram'],
                    'source' => 'api',
                    'fetched_at' => $row['fetched_at'] ?? $now,
                    'created_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('metal_rates_fetch_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeRates(array $payload): array
    {
        $out = [];

        $add = function (string $metal, string $purity, mixed $rate, mixed $fetchedAt = null) use (&$out): void {
            $numericRate = is_numeric($rate) ? (float) $rate : null;
            if ($numericRate === null || $numericRate <= 0) {
                return;
            }
            $out[] = [
                'metal_type' => $metal,
                'purity' => $purity,
                'rate_per_gram' => round($numericRate, 4),
                'fetched_at' => $this->parseFetchedAt($fetchedAt),
            ];
        };

        $fetchedAt = $payload['fetched_at'] ?? $payload['timestamp'] ?? null;

            // Generic payload formats.
            $add('gold', '24k', data_get($payload, 'gold_24k', data_get($payload, 'gold.24k')), $fetchedAt);
            $add('gold', '22k', data_get($payload, 'gold_22k', data_get($payload, 'gold.22k')), $fetchedAt);
            $add('gold', '18k', data_get($payload, 'gold_18k', data_get($payload, 'gold.18k')), $fetchedAt);
            $add('silver', '999', data_get($payload, 'silver_999', data_get($payload, 'silver.999')), $fetchedAt);
            $add('platinum', '999', data_get($payload, 'platinum_999', data_get($payload, 'platinum.999')), $fetchedAt);

            // GoldAPI format: /api/XAU/USD usually returns price_gram_24k and/or price (per troy ounce).
            $add('gold', '24k', data_get($payload, 'price_gram_24k'), $fetchedAt);
            $add('gold', '22k', data_get($payload, 'price_gram_22k'), $fetchedAt);
            $add('gold', '21k', data_get($payload, 'price_gram_21k'), $fetchedAt);
            $add('gold', '20k', data_get($payload, 'price_gram_20k'), $fetchedAt);
            $add('gold', '18k', data_get($payload, 'price_gram_18k'), $fetchedAt);

            // Fallback: if only ounce price is available, convert to 24k per gram.
            if (!collect($out)->contains(fn ($r) => $r['metal_type'] === 'gold' && $r['purity'] === '24k')) {
                $ouncePrice = data_get($payload, 'price');
                if (is_numeric($ouncePrice) && (float) $ouncePrice > 0) {
                    $perGram24k = ((float) $ouncePrice) / 31.1034768;
                    $add('gold', '24k', $perGram24k, $fetchedAt);
                }
            }

        $gold24 = collect($out)->firstWhere(fn ($r) => $r['metal_type'] === 'gold' && $r['purity'] === '24k');
        if ($gold24) {
            $has22 = collect($out)->contains(fn ($r) => $r['metal_type'] === 'gold' && $r['purity'] === '22k');
            $has18 = collect($out)->contains(fn ($r) => $r['metal_type'] === 'gold' && $r['purity'] === '18k');
            if (!$has22) {
                $add('gold', '22k', ((float) $gold24['rate_per_gram']) * (22 / 24), $gold24['fetched_at']);
            }
            if (!$has18) {
                $add('gold', '18k', ((float) $gold24['rate_per_gram']) * (18 / 24), $gold24['fetched_at']);
            }
        }

        return $out;
    }

    private function parseFetchedAt(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return Carbon::parse((string) $value);
    }
}
