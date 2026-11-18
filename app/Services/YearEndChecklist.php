<?php

namespace App\Services;

use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Before you close": what is still open in a financial year when the owner closes it. Warnings, not blocks —
 * the owner decides (Article IV: guided flows keep an expert escape path). Read-only.
 */
final class YearEndChecklist
{
    /** A rate this many days before 31 March still counts as the year-end rate (shops skip holidays). */
    private const RATE_WINDOW_DAYS = 7;

    /** @return list<array{key: string, label: string, count: int}> */
    public function for(int $shopId, FinancialYear $fy): array
    {
        $start = $fy->startDate() . ' 00:00:00';
        $nextStart = $fy->next()->startDate() . ' 00:00:00';
        $end = $fy->endDate();

        $draftBills = DB::table('invoices')->where('shop_id', $shopId)->where('status', 'draft')
            ->where('created_at', '>=', $start)->where('created_at', '<', $nextStart)->count();

        $pendingReturns = DB::table('return_orders as r')->join('invoices as i', 'i.id', '=', 'r.invoice_id')
            ->where('r.shop_id', $shopId)->where('r.status', 'pending_approval')
            ->where('i.fiscal_year', $fy->label())->count();

        $draftPurchases = DB::table('stock_purchases')->where('shop_id', $shopId)->where('status', 'draft')
            ->whereBetween('purchase_date', [$fy->startDate(), $end])->count();

        $openJobs = DB::table('job_orders')->where('shop_id', $shopId)->whereIn('status', ['issued', 'partial_return'])
            ->where('issue_date', '<=', $end)->count();

        $unknownCost = StockAsAt::itemsInStock($shopId, $end)->filter(fn ($i) => $i->acquisition_cost === null)->count();

        $windowStart = CarbonImmutable::parse($end)->subDays(self::RATE_WINDOW_DAYS - 1)->toDateString();
        $hasRate = DB::table('metal_rates')->where('shop_id', $shopId)
            ->whereBetween('business_date', [$windowStart, $end])->exists();

        return [
            ['key' => 'draft_bills', 'count' => $draftBills,
                'label' => trans_choice(':count unfinished bill (draft) from this year|:count unfinished bills (drafts) from this year', $draftBills, ['count' => $draftBills])],
            ['key' => 'pending_returns', 'count' => $pendingReturns,
                'label' => trans_choice(':count return of this year\'s bills waiting for approval|:count returns of this year\'s bills waiting for approval', $pendingReturns, ['count' => $pendingReturns])],
            ['key' => 'draft_purchases', 'count' => $draftPurchases,
                'label' => trans_choice(':count stock purchase dated this year still in draft|:count stock purchases dated this year still in draft', $draftPurchases, ['count' => $draftPurchases])],
            ['key' => 'open_job_orders', 'count' => $openJobs,
                'label' => trans_choice(':count job order still with a karigar|:count job orders still with karigars', $openJobs, ['count' => $openJobs])],
            ['key' => 'unknown_cost_items', 'count' => $unknownCost,
                'label' => trans_choice(':count piece in stock at year-end has no purchase cost, so it cannot be valued|:count pieces in stock at year-end have no purchase cost, so they cannot be valued', $unknownCost, ['count' => $unknownCost])],
            ['key' => 'year_end_rate', 'count' => $hasRate ? 0 : 1,
                'label' => __('No metal rate was entered in the last week up to 31 March, so year-end stock has no market value')],
        ];
    }
}
