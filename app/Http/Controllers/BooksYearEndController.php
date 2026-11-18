<?php

namespace App\Http\Controllers;

use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Owner-only, read-only "Year-end snapshot" page, plus the explicit button that takes a 'closing' snapshot. */
class BooksYearEndController extends Controller
{
    public function show(Request $request, FiscalYearSnapshotService $service, ?string $fy = null)
    {
        $year = $this->year($fy) ?? FinancialYear::current()->previous();
        $shopId = (int) Auth::user()->shop_id;
        $snapshot = $service->latest($shopId, $year);
        $totals = $snapshot ? (is_string($snapshot->totals) ? json_decode($snapshot->totals, true) : (array) $snapshot->totals) : null;

        return view('books.year-end', [
            'year' => $year,
            'snapshot' => $snapshot,
            'totals' => $totals,
            'changes' => $snapshot ? $service->changesSince($snapshot) : null,
            // Shown when the numbers rest on rebuilt (pre-ledger) stock history: the snapshot's own basis, else the year's.
            'historyWarning' => ($snapshot ? $service->basisOf($snapshot) : \App\Services\StockHistory::basisForYear($shopId, $year)) === \App\Services\StockHistory::RECONSTRUCTED
                ? \App\Services\StockHistory::warning($shopId) : null,
            'checklist' => app(\App\Services\YearEndChecklist::class)->for($shopId, $year),
            'years' => [FinancialYear::current()->previous(), FinancialYear::current()->previous()->previous()],
        ]);
    }

    public function take(Request $request, FiscalYearSnapshotService $service, string $fy)
    {
        $year = $this->year($fy) ?? abort(404);
        $user = Auth::user();
        try {
            $service->take((int) $user->shop_id, $year, FiscalYearSnapshotService::KIND_CLOSING, (int) $user->id);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('books.year-end', ['fy' => $year->label()])->withErrors(['snapshot' => $e->getMessage()]);
        }

        return redirect()->route('books.year-end', ['fy' => $year->label()])->with('success', __('Snapshot taken.'));
    }

    private function year(?string $label): ?FinancialYear
    {
        if ($label === null) {
            return null;
        }
        try {
            return FinancialYear::fromLabel($label);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }
}
