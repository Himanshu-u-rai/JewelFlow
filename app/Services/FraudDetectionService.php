<?php
namespace App\Services;

use App\Models\Platform\PlatformFraudFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FraudDetectionService
{
    public function runAllChecks(): array
    {
        $flagged = [];
        $flagged = array_merge($flagged, $this->checkInvoiceSpike());
        $flagged = array_merge($flagged, $this->checkBulkCustomers());
        $flagged = array_merge($flagged, $this->checkCrossTenantPan());
        return $flagged;
    }

    /** Shops invoicing >200% of their 30-day average in one day */
    private function checkInvoiceSpike(): array
    {
        $flagged = [];
        try {
            // Average daily invoice count per shop over last 30 days
            $averages = DB::table('invoices')
                ->select('shop_id', DB::raw('count(*)::float / 30 as daily_avg'))
                ->where('status', 'finalized')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('shop_id')
                ->having(DB::raw('count(*)::float / 30'), '>', 0)
                ->get()
                ->keyBy('shop_id');

            // Today's counts
            $todayCounts = DB::table('invoices')
                ->select('shop_id', DB::raw('count(*) as today_count'))
                ->where('status', 'finalized')
                ->whereDate('created_at', today())
                ->groupBy('shop_id')
                ->get()
                ->keyBy('shop_id');

            foreach ($todayCounts as $shopId => $today) {
                $avg = $averages[$shopId]->daily_avg ?? 1;
                if ($today->today_count > ($avg * 2) && $today->today_count >= 10) {
                    $exists = PlatformFraudFlag::where('shop_id', $shopId)
                        ->where('flag_type', PlatformFraudFlag::TYPE_INVOICE_SPIKE)
                        ->whereRaw('reviewed IS FALSE')
                        ->whereDate('created_at', today())
                        ->exists();
                    if (!$exists) {
                        PlatformFraudFlag::create([
                            'shop_id'   => $shopId,
                            'flag_type' => PlatformFraudFlag::TYPE_INVOICE_SPIKE,
                            'flag_data' => ['today_count' => $today->today_count, 'daily_avg' => round($avg, 2)],
                        ]);
                        $flagged[] = $shopId;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FraudDetectionService::checkInvoiceSpike: ' . $e->getMessage());
        }
        return $flagged;
    }

    /** Shops creating >50 new customers in one day */
    private function checkBulkCustomers(): array
    {
        $flagged = [];
        try {
            $bulkShops = DB::table('customers')
                ->select('shop_id', DB::raw('count(*) as count'))
                ->whereDate('created_at', today())
                ->groupBy('shop_id')
                ->having(DB::raw('count(*)'), '>', 50)
                ->get();

            foreach ($bulkShops as $row) {
                $exists = PlatformFraudFlag::where('shop_id', $row->shop_id)
                    ->where('flag_type', PlatformFraudFlag::TYPE_BULK_CUSTOMERS)
                    ->whereRaw('reviewed IS FALSE')
                    ->whereDate('created_at', today())
                    ->exists();
                if (!$exists) {
                    PlatformFraudFlag::create([
                        'shop_id'   => $row->shop_id,
                        'flag_type' => PlatformFraudFlag::TYPE_BULK_CUSTOMERS,
                        'flag_data' => ['customer_count_today' => $row->count],
                    ]);
                    $flagged[] = $row->shop_id;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FraudDetectionService::checkBulkCustomers: ' . $e->getMessage());
        }
        return $flagged;
    }

    /** Same PAN used across multiple shops */
    private function checkCrossTenantPan(): array
    {
        $flagged = [];
        try {
            // Find PANs that appear in >1 shop
            $crossPans = DB::table('customers')
                ->select('pan', DB::raw('count(distinct shop_id) as shop_count'), DB::raw('array_agg(distinct shop_id) as shop_ids'))
                ->whereNotNull('pan')
                ->where('pan', '!=', '')
                ->groupBy('pan')
                ->having(DB::raw('count(distinct shop_id)'), '>', 1)
                ->limit(100)
                ->get();

            foreach ($crossPans as $row) {
                $shopIds = is_array($row->shop_ids) ? $row->shop_ids : json_decode($row->shop_ids, true) ?? [];
                foreach ($shopIds as $shopId) {
                    $exists = PlatformFraudFlag::where('shop_id', $shopId)
                        ->where('flag_type', PlatformFraudFlag::TYPE_CROSS_TENANT_PAN)
                        ->whereRaw('reviewed IS FALSE')
                        ->where('flag_data->pan', $row->pan)
                        ->exists();
                    if (!$exists) {
                        PlatformFraudFlag::create([
                            'shop_id'   => $shopId,
                            'flag_type' => PlatformFraudFlag::TYPE_CROSS_TENANT_PAN,
                            'flag_data' => ['pan' => $row->pan, 'shop_count' => $row->shop_count, 'all_shop_ids' => $shopIds],
                        ]);
                        $flagged[] = $shopId;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FraudDetectionService::checkCrossTenantPan: ' . $e->getMessage());
        }
        return $flagged;
    }
}
