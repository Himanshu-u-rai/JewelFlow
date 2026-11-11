<?php

namespace App\Services\Dhiran;

use App\Models\Dhiran\DhiranLoan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dhiran CSV exports (owner 2026-11-11: a real data export, not a copy of the columns on screen). Each export takes
 * the page's filtered query and writes every matching record with its full detail. Rows are read in chunks.
 */
final class DhiranExports
{
    private const LOAN_HEADERS = [
        '#', 'Loan number', 'Status', 'Loan date', 'Tenure (months)', 'Maturity date', 'Days to maturity', 'Days overdue',
        'Grace ends', 'Customer', 'Customer code', 'Mobile', 'Items', 'Metal', 'Gross weight (g)', 'Net weight (g)',
        'Fine weight (g)', 'Gold rate on date', 'Silver rate on date', 'Collateral value', 'LTV %', 'Principal',
        'Processing fee', 'Interest %/month', 'Interest type', 'Penalty %/month', 'Outstanding principal',
        'Outstanding interest', 'Outstanding penalty', 'Outstanding', 'Interest accrued through', 'Interest collected',
        'Penalty collected', 'Principal collected', 'Profit', 'Written off', 'Payments', 'Last payment', 'Renewed count',
        'Renewed from', 'Forfeiture notice sent', 'Closed on', 'Forfeited on', 'Created by', 'Created at', 'Notes',
    ];

    /** Loans with everything about them; used by the Loans list and the loan-based reports. */
    public function loans(Builder $query, string $name): StreamedResponse
    {
        $query->with([
            'customer:id,first_name,last_name,mobile,customer_code',
            'items:id,dhiran_loan_id,metal_type,gross_weight,net_metal_weight',
            'creator:id,name',
        ])
            ->withCount(['items', 'payments'])
            ->withMax('payments', 'payment_date')
            ->addSelect(['renewed_from_number' => DB::table('dhiran_loans as r')->select('r.loan_number')->whereColumn('r.id', 'dhiran_loans.renewed_from_id')->limit(1)]);

        $m = [DhiranCsv::class, 'money'];
        $d = [DhiranCsv::class, 'date'];
        $rows = function () use ($query, $m, $d) {
            $n = 0;
            foreach ($query->lazy(500) as $loan) {
                $outstanding = $loan->totalOutstanding();
                $graceEnd = $loan->maturity_date && $loan->grace_period_days ? $loan->maturity_date->copy()->addDays($loan->grace_period_days) : $loan->maturity_date;
                yield [
                    ++$n, $loan->loan_number, DhiranListFilters::STATUSES[$loan->status] ?? ucfirst((string) $loan->status),
                    $d($loan->loan_date), $loan->tenure_months, $d($loan->maturity_date),
                    $loan->status === 'active' ? $loan->daysTillMaturity() : null,
                    $loan->status === 'active' ? $loan->daysOverdue() : null, $d($graceEnd),
                    $loan->customer?->name, $loan->customer?->customer_code, $loan->customer?->mobile,
                    $loan->items_count,
                    $loan->items->pluck('metal_type')->filter()->unique()->map(fn ($x) => ucfirst($x))->implode(', '),
                    $this->grams($loan->items->sum('gross_weight')), $this->grams($loan->items->sum('net_metal_weight')),
                    $this->grams($loan->total_fine_weight),
                    $m($loan->gold_rate_on_date), $m($loan->silver_rate_on_date), $m($loan->total_collateral_value),
                    $m($loan->ltv_ratio_applied), $m($loan->principal_amount), $m($loan->processing_fee),
                    $m($loan->interest_rate_monthly), $loan->interest_type ? ucfirst($loan->interest_type) : null, $m($loan->penalty_rate_monthly),
                    $m($loan->outstanding_principal ?? 0), $m($loan->outstanding_interest ?? 0), $m($loan->outstanding_penalty ?? 0), $m($outstanding),
                    $d($loan->interest_accrued_through),
                    $m($loan->total_interest_collected ?? 0), $m($loan->total_penalty_collected ?? 0), $m($loan->total_principal_collected ?? 0),
                    $m((float) $loan->total_interest_collected + (float) $loan->total_penalty_collected + (float) $loan->processing_fee),
                    $loan->status === 'forfeited' ? $m($outstanding) : null,
                    $loan->payments_count, $d($loan->payments_max_payment_date),
                    $loan->renewed_count, $loan->renewed_from_number,
                    $d($loan->forfeiture_notice_sent_at), $d($loan->closed_at), $d($loan->forfeited_at),
                    $loan->creator?->name, DhiranCsv::dateTime($loan->created_at), $loan->notes,
                ];
            }
        };

        return DhiranCsv::download($name, self::LOAN_HEADERS, $rows());
    }

    /** Payments (the Interest report): every part of each payment and what was left owing after it. */
    public function payments(Builder $query, string $name): StreamedResponse
    {
        $query->with(['loan:id,loan_number,customer_id', 'loan.customer:id,first_name,last_name,mobile,customer_code', 'creator:id,name']);
        $m = [DhiranCsv::class, 'money'];
        $rows = function () use ($query, $m) {
            $n = 0;
            foreach ($query->lazy(500) as $p) {
                yield [
                    ++$n, $p->receipt_number, DhiranCsv::date($p->payment_date), ucwords(str_replace('_', ' ', (string) $p->type)),
                    $p->loan?->loan_number, $p->loan?->customer?->name, $p->loan?->customer?->customer_code, $p->loan?->customer?->mobile,
                    \App\Models\Dhiran\DhiranPayment::methodLabel($p->payment_method),
                    $m($p->amount), $m($p->interest_component), $m($p->penalty_component), $m($p->principal_component), $m($p->processing_fee_component),
                    $m($p->outstanding_principal_after), $m($p->outstanding_interest_after), $m($p->outstanding_penalty_after),
                    $p->creator?->name, DhiranCsv::dateTime($p->created_at), $p->notes,
                ];
            }
        };

        return DhiranCsv::download($name, [
            '#', 'Receipt number', 'Payment date', 'Type', 'Loan number', 'Customer', 'Customer code', 'Mobile', 'Method',
            'Amount', 'Interest', 'Penalty', 'Principal', 'Processing fee', 'Outstanding principal after',
            'Outstanding interest after', 'Outstanding penalty after', 'Recorded by', 'Recorded at', 'Notes',
        ], $rows());
    }

    /**
     * Cash book: opening balance, every entry of the period with its running balance, closing balance. With the
     * in/out filter on, rows skip the other kind, so per-row running balances are left blank.
     *
     * @param array{opening: float, in: float, out: float, net: float, closing: float} $totals
     */
    public function cashbook(Builder $query, array $totals, bool $running, string $from, string $to): StreamedResponse
    {
        $query->with(['loan:id,loan_number,customer_id', 'loan.customer:id,first_name,last_name', 'payment:id,receipt_number', 'creator:id,name']);
        $m = [DhiranCsv::class, 'money'];
        $rows = function () use ($query, $totals, $running, $from, $m) {
            yield ['', 'Opening balance', '', '', '', '', '', '', $m($totals['opening']), 'Balance before ' . $from];
            $balance = $totals['opening'];
            $n = 0;
            foreach ($query->lazy(500) as $e) {
                $signed = $e->type === 'in' ? (float) $e->amount : -(float) $e->amount;
                $balance += $signed;
                yield [
                    ++$n, DhiranCsv::date($e->entry_date), $e->loan?->loan_number, $e->loan?->customer?->name,
                    $e->type === 'in' ? 'In' : 'Out', ucfirst(str_replace('_', ' ', (string) $e->source_type)), $m($signed),
                    \App\Models\Dhiran\DhiranPayment::methodLabel($e->payment_method), $running ? $m($balance) : null,
                    $e->description, $e->payment?->receipt_number, $e->creator?->name, DhiranCsv::dateTime($e->created_at),
                ];
            }
            yield ['', 'Closing balance', '', '', '', '', $m($totals['net']), '', $m($totals['closing']),
                'In ' . $m($totals['in']) . ', out ' . $m($totals['out'])];
        };

        return DhiranCsv::download("cash-book-{$from}-to-{$to}", [
            '#', 'Date', 'Loan number', 'Customer', 'Type', 'Source', 'Amount', 'Method', 'Running balance',
            'Description', 'Receipt number', 'Recorded by', 'Recorded at',
        ], $rows());
    }

    /** Customers with their contact details and where their loans stand. PAN is masked: the file travels. */
    public function customers(Builder $query): StreamedResponse
    {
        $open = ['active', 'pending_evidence'];
        $loans = fn () => DB::table('dhiran_loans as l')->whereColumn('l.customer_id', 'customers.id');
        $query->addSelect([
            'outstanding_sum' => $loans()->whereIn('l.status', $open)->selectRaw('coalesce(sum(coalesce(l.outstanding_principal, 0) + coalesce(l.outstanding_interest, 0) + coalesce(l.outstanding_penalty, 0)), 0)'),
            'borrowed_sum' => $loans()->selectRaw('coalesce(sum(l.principal_amount), 0)'),
            'first_loan' => $loans()->selectRaw('min(l.loan_date)'),
            'last_loan' => $loans()->selectRaw('max(l.loan_date)'),
        ]);
        $m = [DhiranCsv::class, 'money'];
        $rows = function () use ($query, $m) {
            $n = 0;
            foreach ($query->lazy(500) as $c) {
                yield [
                    ++$n, $c->customer_code, $c->name, $c->mobile, $c->email, $c->address, $this->maskPan($c->pan),
                    DhiranCsv::date($c->date_of_birth), $c->loans_count, $c->active_loans_count, $c->pending_evidence_count,
                    $c->closed_loans_count, $c->forfeited_loans_count, $m($c->borrowed_sum), $m($c->outstanding_sum),
                    DhiranCsv::date($c->first_loan), DhiranCsv::date($c->last_loan), DhiranCsv::date($c->created_at), $c->notes,
                ];
            }
        };

        return DhiranCsv::download('customers-' . now()->toDateString(), [
            '#', 'Customer code', 'Name', 'Mobile', 'Email', 'Address', 'PAN (masked)', 'Date of birth', 'Loans', 'Active',
            'Awaiting evidence', 'Closed', 'Forfeited', 'Total borrowed', 'Outstanding', 'First loan', 'Last loan',
            'Customer since', 'Notes',
        ], $rows());
    }

    private function grams(mixed $v): ?string
    {
        return $v === null ? null : number_format((float) $v, 3, '.', '');
    }

    private function maskPan(?string $pan): ?string
    {
        if (! $pan) {
            return null;
        }
        $len = strlen($pan);

        return $len <= 5 ? str_repeat('X', $len) : substr($pan, 0, 3) . str_repeat('X', $len - 5) . substr($pan, -2);
    }
}
