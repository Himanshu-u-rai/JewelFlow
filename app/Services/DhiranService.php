<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLedgerEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class DhiranService
{
    /* ══════════════════════════════════════════════════════════
     *  1. CREATE LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Create a new gold-pledge loan with items, disbursement, and optional processing fee.
     *
     * @param  array  $items   Each item: description, category?, metal_type?, quantity?, gross_weight, stone_weight?, purity, rate_per_gram_at_pledge, photo_path?, huid?
     * @param  array  $params  principal_amount, loan_date?, gold_rate_on_date, silver_rate_on_date?, interest_rate_monthly?, interest_type?, penalty_rate_monthly?, tenure_months?, min_lock_months?, grace_period_days?, min_interest_months?, processing_fee?, processing_fee_type?, kyc_aadhaar?, kyc_pan?, kyc_photo_path?, terms_text?, notes?, payment_method?, created_by?
     */
    public function createLoan(Shop $shop, Customer $customer, array $items, array $params): DhiranLoan
    {
        return DB::transaction(function () use ($shop, $customer, $items, $params): DhiranLoan {

            // ── Validate shop ownership ──────────────────────
            if ((int) $customer->shop_id !== (int) $shop->id) {
                throw new LogicException('Customer does not belong to this shop.');
            }

            if (empty($items)) {
                throw new LogicException('At least one collateral item is required.');
            }

            $settings = DhiranSettings::getForShop($shop->id);

            // ── Resolve parameters with settings defaults ────
            $principalAmount      = (float) $params['principal_amount'];
            $interestRateMonthly  = (float) ($params['interest_rate_monthly'] ?? $settings->default_interest_rate_monthly);
            $interestType         = $params['interest_type'] ?? $settings->default_interest_type ?? 'flat';
            $penaltyRateMonthly   = (float) ($params['penalty_rate_monthly'] ?? $settings->default_penalty_rate_monthly);
            $tenureMonths         = (int) ($params['tenure_months'] ?? $settings->default_tenure_months);
            $minLockMonths        = (int) ($params['min_lock_months'] ?? $settings->default_min_lock_months);
            $gracePeriodDays      = (int) ($params['grace_period_days'] ?? $settings->grace_period_days);
            $minInterestMonths    = (int) ($params['min_interest_months'] ?? $settings->default_min_interest_months);
            $loanDate             = Carbon::parse($params['loan_date'] ?? today());
            $goldRateOnDate       = (float) $params['gold_rate_on_date'];
            $silverRateOnDate     = isset($params['silver_rate_on_date']) ? (float) $params['silver_rate_on_date'] : null;
            $paymentMethod        = $params['payment_method'] ?? 'cash';
            $createdBy            = $params['created_by'] ?? null;

            // ── Compute per-item values ──────────────────────
            $computedItems    = [];
            $totalFineWeight  = 0.0;
            $totalCollateral  = 0.0;

            foreach ($items as $item) {
                $grossWeight     = (float) $item['gross_weight'];
                $stoneWeight     = (float) ($item['stone_weight'] ?? 0);
                $purity          = (float) $item['purity'];
                $ratePerGram     = (float) $item['rate_per_gram_at_pledge'];

                $netMetalWeight  = $grossWeight - $stoneWeight;
                $fineWeight      = $netMetalWeight * $purity / 24;
                $marketValue     = round($fineWeight * $ratePerGram, 2);

                $totalFineWeight += $fineWeight;
                $totalCollateral += $marketValue;

                $computedItems[] = array_merge($item, [
                    'net_metal_weight'       => round($netMetalWeight, 6),
                    'fine_weight'            => round($fineWeight, 6),
                    'market_value'           => $marketValue,
                    // loan_value will be set after LTV is determined
                ]);
            }

            // ── Tiered LTV from settings ─────────────────────
            if ($principalAmount >= (float) $settings->high_value_threshold) {
                $ltvRatio = (float) $settings->high_value_ltv_ratio;
            } else {
                $ltvRatio = (float) $settings->default_ltv_ratio;
            }

            // Allow override but cap at settings value
            if (isset($params['ltv_ratio_applied'])) {
                $ltvRatio = min((float) $params['ltv_ratio_applied'], $ltvRatio);
            }

            // ── Apply LTV to each item ───────────────────────
            $totalLoanValue = 0.0;
            foreach ($computedItems as &$ci) {
                $ci['loan_value'] = round($ci['market_value'] * $ltvRatio / 100, 2);
                $totalLoanValue  += $ci['loan_value'];
            }
            unset($ci);

            // ── Validate principal against collateral ────────
            if ($principalAmount > $totalLoanValue) {
                throw new LogicException(
                    "Principal amount ({$principalAmount}) exceeds maximum loan value ({$totalLoanValue}) based on LTV ratio ({$ltvRatio}%)."
                );
            }

            // ── Validate min/max loan amount ─────────────────
            $minLoan = (float) $settings->min_loan_amount;
            $maxLoan = (float) $settings->max_loan_amount;

            if ($principalAmount < $minLoan) {
                throw new LogicException("Principal amount ({$principalAmount}) is below minimum loan amount ({$minLoan}).");
            }
            if ($principalAmount > $maxLoan) {
                throw new LogicException("Principal amount ({$principalAmount}) exceeds maximum loan amount ({$maxLoan}).");
            }

            // ── Processing fee ───────────────────────────────
            $processingFeeType = $params['processing_fee_type'] ?? $settings->processing_fee_type ?? 'flat';
            if (isset($params['processing_fee'])) {
                $processingFee = (float) $params['processing_fee'];
            } else {
                $feeValue = (float) ($settings->processing_fee_value ?? 0);
                if ($processingFeeType === 'percent') {
                    $processingFee = round($principalAmount * $feeValue / 100, 2);
                } else {
                    $processingFee = $feeValue;
                }
            }

            // ── Generate loan number ─────────────────────────
            $loanNumber = $this->generateLoanNumber($shop);

            // ── Create loan record ───────────────────────────
            $maturityDate = $loanDate->copy()->addMonths($tenureMonths);

            $loan = DhiranLoan::create([
                'shop_id'                  => $shop->id,
                'customer_id'              => $customer->id,
                'loan_number'              => $loanNumber,
                'loan_date'                => $loanDate->toDateString(),
                'gold_rate_on_date'        => $goldRateOnDate,
                'silver_rate_on_date'      => $silverRateOnDate,
                'principal_amount'         => $principalAmount,
                'processing_fee'           => $processingFee,
                'processing_fee_type'      => $processingFeeType,
                'interest_rate_monthly'    => $interestRateMonthly,
                'interest_type'            => $interestType,
                'penalty_rate_monthly'     => $penaltyRateMonthly,
                'ltv_ratio_applied'        => $ltvRatio,
                'total_collateral_value'   => round($totalCollateral, 2),
                'total_fine_weight'        => round($totalFineWeight, 6),
                'outstanding_principal'    => $principalAmount,
                'outstanding_interest'     => 0,
                'outstanding_penalty'      => 0,
                'interest_accrued_through' => $loanDate->toDateString(),
                'total_interest_collected' => 0,
                'total_penalty_collected'  => 0,
                'total_principal_collected' => 0,
                'tenure_months'            => $tenureMonths,
                'maturity_date'            => $maturityDate->toDateString(),
                'min_lock_months'          => $minLockMonths,
                'grace_period_days'        => $gracePeriodDays,
                'min_interest_months'      => $minInterestMonths,
                'status'                   => 'active',
                'renewed_count'            => 0,
                'renewed_from_id'          => $params['renewed_from_id'] ?? null,
                'kyc_aadhaar'              => $params['kyc_aadhaar'] ?? null,
                'kyc_pan'                  => $params['kyc_pan'] ?? null,
                'kyc_photo_path'           => $params['kyc_photo_path'] ?? null,
                'terms_text'               => $params['terms_text'] ?? $settings->receipt_terms_text ?? null,
                'notes'                    => $params['notes'] ?? null,
                'created_by'               => $createdBy,
            ]);

            // ── Create loan items ────────────────────────────
            foreach ($computedItems as $ci) {
                DhiranLoanItem::create([
                    'shop_id'                => $shop->id,
                    'dhiran_loan_id'         => $loan->id,
                    'description'            => $ci['description'],
                    'category'               => $ci['category'] ?? null,
                    'metal_type'             => $ci['metal_type'] ?? 'gold',
                    'quantity'               => (int) ($ci['quantity'] ?? 1),
                    'gross_weight'           => $ci['gross_weight'],
                    'stone_weight'           => $ci['stone_weight'] ?? 0,
                    'net_metal_weight'       => $ci['net_metal_weight'],
                    'purity'                 => $ci['purity'],
                    'fine_weight'            => $ci['fine_weight'],
                    'rate_per_gram_at_pledge' => $ci['rate_per_gram_at_pledge'],
                    'market_value'           => $ci['market_value'],
                    'loan_value'             => $ci['loan_value'],
                    'photo_path'             => $ci['photo_path'] ?? null,
                    'huid'                   => $ci['huid'] ?? null,
                    'status'                 => 'pledged',
                ]);
            }

            // ── Disbursement payment (cash out) ──────────────
            $disbursement = DhiranPayment::record([
                'shop_id'                    => $shop->id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => $loanDate->toDateString(),
                'type'                       => 'disbursement',
                'amount'                     => $principalAmount,
                'direction'                  => 'out',
                'payment_method'             => $paymentMethod,
                'interest_component'         => 0,
                'penalty_component'          => 0,
                'principal_component'        => $principalAmount,
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => $principalAmount,
                'outstanding_interest_after' => 0,
                'outstanding_penalty_after'  => 0,
                'notes'                      => "Loan disbursed: {$loanNumber}",
                'created_by'                 => $createdBy,
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $shop->id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $disbursement->id,
                'entry_date'         => $loanDate->toDateString(),
                'type'               => 'out',
                'amount'             => $principalAmount,
                'source_type'        => 'disbursement',
                'payment_method'     => $paymentMethod,
                'description'        => "Loan disbursed: {$loanNumber}",
                'created_by'         => $createdBy,
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $shop->id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $disbursement->id,
                'entry_type'              => 'disbursement',
                'direction'               => 'debit',
                'amount'                  => $principalAmount,
                'balance_after'           => $principalAmount,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Loan disbursed: {$loanNumber}",
                'created_by'              => $createdBy,
            ]);

            // ── Processing fee payment (cash in) if > 0 ─────
            if ($processingFee > 0) {
                $feePayment = DhiranPayment::record([
                    'shop_id'                    => $shop->id,
                    'dhiran_loan_id'             => $loan->id,
                    'payment_date'               => $loanDate->toDateString(),
                    'type'                       => 'processing_fee',
                    'amount'                     => $processingFee,
                    'direction'                  => 'in',
                    'payment_method'             => $paymentMethod,
                    'interest_component'         => 0,
                    'penalty_component'          => 0,
                    'principal_component'        => 0,
                    'processing_fee_component'   => $processingFee,
                    'outstanding_principal_after' => $principalAmount,
                    'outstanding_interest_after' => 0,
                    'outstanding_penalty_after'  => 0,
                    'notes'                      => "Processing fee: {$loanNumber}",
                    'created_by'                 => $createdBy,
                ]);

                DhiranCashEntry::record([
                    'shop_id'            => $shop->id,
                    'dhiran_loan_id'     => $loan->id,
                    'dhiran_payment_id'  => $feePayment->id,
                    'entry_date'         => $loanDate->toDateString(),
                    'type'               => 'in',
                    'amount'             => $processingFee,
                    'source_type'        => 'processing_fee',
                    'payment_method'     => $paymentMethod,
                    'description'        => "Processing fee: {$loanNumber}",
                    'created_by'         => $createdBy,
                ]);

                DhiranLedgerEntry::record([
                    'shop_id'                 => $shop->id,
                    'dhiran_loan_id'          => $loan->id,
                    'dhiran_payment_id'       => $feePayment->id,
                    'entry_type'              => 'processing_fee',
                    'direction'               => 'credit',
                    'amount'                  => $processingFee,
                    'balance_after'           => $principalAmount,
                    'interest_balance_after'  => 0,
                    'penalty_balance_after'   => 0,
                    'note'                    => "Processing fee collected: {$loanNumber}",
                    'created_by'              => $createdBy,
                ]);
            }

            return $loan->fresh(['items', 'payments']);
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  2. ACCRUE INTEREST
     * ══════════════════════════════════════════════════════════ */

    /**
     * Calculate and accrue interest (and penalty if overdue) on a loan.
     * Idempotent for the same day — will not double-accrue.
     *
     * Note on flat-interest formula: flat interest is computed as
     * `monthly_interest / 30 * days`, i.e. a fixed 30-day month. This means a
     * 12-month loan held for 365 days will accrue ~12.17 months of interest
     * (since 365 / 30 ≈ 12.17). This is intentional per the flat-rate pawn
     * convention used in the industry — do not "fix" it by switching to
     * calendar-month accounting without a settings-level opt-in.
     */
    public function accrueInterest(DhiranLoan $loan): void
    {
        // Outer guard — cheap optimization to skip non-active loans without
        // opening a transaction. Authoritative re-check happens inside the
        // transaction closure below after we acquire the row lock.
        if ($loan->status !== 'active') {
            return;
        }

        DB::transaction(function () use ($loan): void {
            // Re-fetch with lock to prevent concurrent accrual. All state
            // (days, fromDate) is derived AFTER the lock is held so that two
            // concurrent accrual requests cannot both read the same stale
            // `interest_accrued_through` and double-post interest.
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            // Re-check status under the lock — another process may have
            // closed/renewed/forfeited this loan between the outer guard and
            // here.
            if ($loan->status !== 'active') {
                return;
            }

            $today    = today();
            $fromDate = $loan->interest_accrued_through
                ? Carbon::parse($loan->interest_accrued_through)
                : Carbon::parse($loan->loan_date);

            $days = (int) $fromDate->diffInDays($today, false);

            if ($days <= 0) {
                return;
            }

            $principalAmount       = (float) $loan->principal_amount;
            $outstandingPrincipal  = (float) $loan->outstanding_principal;
            $outstandingInterest   = (float) $loan->outstanding_interest;
            $outstandingPenalty    = (float) $loan->outstanding_penalty;
            $interestRateMonthly   = (float) $loan->interest_rate_monthly;
            $penaltyRateMonthly    = (float) $loan->penalty_rate_monthly;
            $interestType          = $loan->interest_type;

            // ── Interest accrual ─────────────────────────────
            $interestAccrual = 0.0;

            switch ($interestType) {
                case 'flat':
                    // Flat: always on ORIGINAL principal, not outstanding
                    $monthlyInterest = $principalAmount * $interestRateMonthly / 100;
                    $interestAccrual = $monthlyInterest / 30 * $days;
                    break;

                case 'daily':
                    $interestAccrual = $principalAmount * ($interestRateMonthly / 30) / 100 * $days;
                    break;

                case 'compound':
                    // Compound: on outstanding_principal + outstanding_interest
                    $base = $outstandingPrincipal + $outstandingInterest;
                    $interestAccrual = $base * $interestRateMonthly / 100 / 30 * $days;
                    break;

                default:
                    // Fallback to flat
                    $monthlyInterest = $principalAmount * $interestRateMonthly / 100;
                    $interestAccrual = $monthlyInterest / 30 * $days;
                    break;
            }

            $interestAccrual = round($interestAccrual, 2);

            // ── Penalty accrual (if overdue past grace) ──────
            $penaltyAccrual = 0.0;

            if ($loan->maturity_date && $penaltyRateMonthly > 0) {
                $gracePeriodEnd = Carbon::parse($loan->maturity_date)->addDays($loan->grace_period_days);

                if ($today->gt($gracePeriodEnd)) {
                    // Only accrue penalty for the overdue days within this accrual period
                    $overduePeriodStart = $fromDate->gt($gracePeriodEnd) ? $fromDate : $gracePeriodEnd;
                    $overdueDaysInPeriod = (int) $overduePeriodStart->diffInDays($today, false);

                    if ($overdueDaysInPeriod > 0) {
                        $penaltyAccrual = round(
                            $outstandingPrincipal * $penaltyRateMonthly / 100 / 30 * $overdueDaysInPeriod,
                            2
                        );
                    }
                }
            }

            // ── Update loan balances ─────────────────────────
            $newOutstandingInterest = round($outstandingInterest + $interestAccrual, 2);
            $newOutstandingPenalty  = round($outstandingPenalty + $penaltyAccrual, 2);

            $loan->outstanding_interest     = $newOutstandingInterest;
            $loan->outstanding_penalty      = $newOutstandingPenalty;
            $loan->interest_accrued_through = $today->toDateString();
            $loan->save();

            // ── Ledger entry for interest accrual ────────────
            if ($interestAccrual > 0) {
                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'interest_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $interestAccrual,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => $newOutstandingInterest,
                    'penalty_balance_after'   => $newOutstandingPenalty,
                    'note'                    => "Interest accrual: {$days} days ({$loan->interest_type})",
                    'meta'                    => json_encode([
                        'days'            => $days,
                        'interest_type'   => $interestType,
                        'rate_monthly'    => $interestRateMonthly,
                        'accrual_amount'  => $interestAccrual,
                    ]),
                ]);
            }

            // ── Ledger entry for penalty accrual ─────────────
            if ($penaltyAccrual > 0) {
                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'penalty_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $penaltyAccrual,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => $newOutstandingInterest,
                    'penalty_balance_after'   => $newOutstandingPenalty,
                    'note'                    => "Penalty accrual: overdue period",
                    'meta'                    => json_encode([
                        'penalty_rate_monthly' => $penaltyRateMonthly,
                        'penalty_amount'       => $penaltyAccrual,
                    ]),
                ]);
            }
        });

        // Refresh model in-place so callers see updated values. No additional
        // mid-transaction refresh is required: ledger inserts above use the
        // locally-updated values directly rather than re-reading $loan.
        $loan->refresh();
    }

    /* ══════════════════════════════════════════════════════════
     *  3. RECORD PAYMENT (penalty → interest → principal)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Record a general payment. Splits: penalty first, then interest, then principal.
     * Auto-closes loan if balance reaches zero.
     */
    public function recordPayment(DhiranLoan $loan, float $amount, string $method = 'cash'): DhiranPayment
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Payments can only be recorded against active loans.');
        }
        if ($amount <= 0) {
            throw new LogicException('Payment amount must be greater than zero.');
        }

        // Accrue interest up to today before applying payment
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $amount, $method): DhiranPayment {
            // Re-fetch with lock
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            $totalOutstanding = $loan->totalOutstanding();

            if ($amount > round($totalOutstanding, 2)) {
                throw new LogicException(
                    "Payment amount ({$amount}) exceeds total outstanding ({$totalOutstanding}). Overpayment not allowed."
                );
            }

            // ── Split payment: penalty → interest → principal
            $remaining = $amount;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);
            $remaining -= $interestComponent;

            $principalComponent = min($remaining, (float) $loan->outstanding_principal);
            // Remaining after principal should be 0 due to overpayment check

            // Derive payment type from the dominant non-zero component so that
            // a payment covering only interest/penalty is not mislabelled as a
            // principal repayment.
            $type = $principalComponent > 0
                ? 'principal_repayment'
                : ($interestComponent > 0 ? 'interest_payment' : 'penalty_payment');

            // ── Update loan balances ─────────────────────────
            $loan->outstanding_penalty    = round((float) $loan->outstanding_penalty - $penaltyComponent, 2);
            $loan->outstanding_interest   = round((float) $loan->outstanding_interest - $interestComponent, 2);
            $loan->outstanding_principal  = round((float) $loan->outstanding_principal - $principalComponent, 2);
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->total_principal_collected = round((float) $loan->total_principal_collected + $principalComponent, 2);
            $loan->save();

            // ── Create payment record ────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => $type,
                'amount'                     => $amount,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => round($principalComponent, 2),
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => (float) $loan->outstanding_principal,
                'outstanding_interest_after' => (float) $loan->outstanding_interest,
                'outstanding_penalty_after'  => (float) $loan->outstanding_penalty,
                'notes'                      => "Payment received: {$loan->loan_number}",
                'created_by'                 => $loan->created_by,
            ]);

            // ── Cash entry ───────────────────────────────────
            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $amount,
                'source_type'        => 'principal_collection',
                'payment_method'     => $method,
                'description'        => "Payment: {$loan->loan_number} (P:{$principalComponent} I:{$interestComponent} Pen:{$penaltyComponent})",
                'created_by'         => $loan->created_by,
            ]);

            // ── Ledger entry ─────────────────────────────────
            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'principal_repayment',
                'direction'               => 'credit',
                'amount'                  => $amount,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Payment applied: P:{$principalComponent} I:{$interestComponent} Pen:{$penaltyComponent}",
                'created_by'              => $loan->created_by,
            ]);

            // ── Auto-close if fully paid ─────────────────────
            // totalOutstanding() is rounded to 2 decimals, so anything under a
            // paisa is effectively zero — avoid float-equality pitfalls.
            if ($loan->totalOutstanding() < 0.01) {
                $this->closeLoanInternal($loan);
            }

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  4. RECORD INTEREST PAYMENT (penalty + interest only)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Record a payment that covers only penalty and interest — no principal reduction.
     */
    public function recordInterestPayment(DhiranLoan $loan, float $amount, string $method = 'cash'): DhiranPayment
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Payments can only be recorded against active loans.');
        }
        if ($amount <= 0) {
            throw new LogicException('Payment amount must be greater than zero.');
        }

        // Accrue interest up to today
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $amount, $method): DhiranPayment {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            $maxPayable = round((float) $loan->outstanding_penalty + (float) $loan->outstanding_interest, 2);

            if ($amount > $maxPayable) {
                throw new LogicException(
                    "Interest payment amount ({$amount}) exceeds outstanding penalty + interest ({$maxPayable})."
                );
            }

            // ── Split: penalty first, then interest ──────────
            $remaining = $amount;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);

            // ── Update loan balances ─────────────────────────
            $loan->outstanding_penalty    = round((float) $loan->outstanding_penalty - $penaltyComponent, 2);
            $loan->outstanding_interest   = round((float) $loan->outstanding_interest - $interestComponent, 2);
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->save();

            // ── Payment record ───────────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => 'interest_payment',
                'amount'                     => $amount,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => 0,
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => (float) $loan->outstanding_principal,
                'outstanding_interest_after' => (float) $loan->outstanding_interest,
                'outstanding_penalty_after'  => (float) $loan->outstanding_penalty,
                'notes'                      => "Interest payment: {$loan->loan_number}",
                'created_by'                 => $loan->created_by,
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $amount,
                'source_type'        => 'interest_collection',
                'payment_method'     => $method,
                'description'        => "Interest payment: {$loan->loan_number} (I:{$interestComponent} Pen:{$penaltyComponent})",
                'created_by'         => $loan->created_by,
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'interest_collection',
                'direction'               => 'credit',
                'amount'                  => $amount,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Interest payment applied: I:{$interestComponent} Pen:{$penaltyComponent}",
                'created_by'              => $loan->created_by,
            ]);

            // ── Auto-close if fully paid ─────────────────────
            // Symmetrical with recordPayment(): although this path never
            // touches principal, a loan whose principal was already settled
            // via prior releaseItem()/recordPayment() calls can have its final
            // interest/penalty cleared here — in which case totalOutstanding()
            // hits zero and the loan should close automatically.
            if ($loan->totalOutstanding() < 0.01) {
                $this->closeLoanInternal($loan);
            }

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  5. RELEASE ITEM (partial release with proportional payment)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Release a single pledged item. Requires proportional principal payment.
     */
    public function releaseItem(
        DhiranLoan $loan,
        DhiranLoanItem $item,
        float $paymentAmount,
        string $method,
        string $conditionNote
    ): DhiranPayment {
        // ── Validations ──────────────────────────────────────
        if ($loan->status !== 'active') {
            throw new LogicException('Items can only be released from active loans.');
        }
        if ((int) $item->dhiran_loan_id !== (int) $loan->id) {
            throw new LogicException('Item does not belong to this loan.');
        }
        if ((int) $item->shop_id !== (int) $loan->shop_id) {
            throw new LogicException('Item does not belong to this shop.');
        }
        if ($item->status !== 'pledged') {
            throw new LogicException('Only pledged items can be released.');
        }

        // Accrue interest first
        $this->accrueInterest($loan);

        $actorId = auth()->id();

        return DB::transaction(function () use ($loan, $item, $paymentAmount, $method, $conditionNote, $actorId): DhiranPayment {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);
            $item = DhiranLoanItem::lockForUpdate()->find($item->id);

            // ── Calculate proportional principal ─────────────
            $totalCollateral = (float) $loan->total_collateral_value;
            if ($totalCollateral <= 0) {
                throw new LogicException('Total collateral value is zero — cannot calculate proportional release.');
            }

            $proportionalPrincipal = round(
                ((float) $item->loan_value / $totalCollateral) * (float) $loan->outstanding_principal,
                2
            );

            if ($paymentAmount < $proportionalPrincipal) {
                throw new LogicException(
                    "Payment amount ({$paymentAmount}) is less than proportional principal ({$proportionalPrincipal}) required to release this item."
                );
            }

            // Cap at total outstanding to prevent overpayment
            $paymentAmount = min($paymentAmount, $loan->totalOutstanding());

            // ── Record the payment (standard split) ──────────
            $remaining = $paymentAmount;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);
            $remaining -= $interestComponent;

            $principalComponent = min($remaining, (float) $loan->outstanding_principal);

            // ── Update loan balances ─────────────────────────
            $loan->outstanding_penalty    = round((float) $loan->outstanding_penalty - $penaltyComponent, 2);
            $loan->outstanding_interest   = round((float) $loan->outstanding_interest - $interestComponent, 2);
            $loan->outstanding_principal  = round((float) $loan->outstanding_principal - $principalComponent, 2);
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->total_principal_collected = round((float) $loan->total_principal_collected + $principalComponent, 2);
            $loan->save();

            // ── Mark item released ───────────────────────────
            $item->status                 = 'released';
            $item->released_at            = now();
            $item->release_condition_note = $conditionNote;
            $item->released_by            = $actorId ?? $loan->created_by;
            $item->save();

            // ── Payment record ───────────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => 'principal_repayment',
                'amount'                     => $paymentAmount,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => round($principalComponent, 2),
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => (float) $loan->outstanding_principal,
                'outstanding_interest_after' => (float) $loan->outstanding_interest,
                'outstanding_penalty_after'  => (float) $loan->outstanding_penalty,
                'notes'                      => "Item release payment: {$item->description}",
                'created_by'                 => $loan->created_by,
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $paymentAmount,
                'source_type'        => 'principal_collection',
                'payment_method'     => $method,
                'description'        => "Item release: {$item->description} — {$loan->loan_number}",
                'created_by'         => $loan->created_by,
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'item_release',
                'direction'               => 'credit',
                'amount'                  => $paymentAmount,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Item released: {$item->description}",
                'meta'                    => json_encode([
                    'item_id'                => $item->id,
                    'proportional_principal' => $proportionalPrincipal,
                    'condition_note'         => $conditionNote,
                ]),
                'created_by'              => $loan->created_by,
            ]);

            // ── If all items released and zero balance → close
            // Tolerance comparison: totalOutstanding() is rounded to 2 dp.
            $pledgedCount = $loan->items()->where('status', 'pledged')->count();
            if ($pledgedCount === 0 && $loan->totalOutstanding() < 0.01) {
                $this->closeLoanInternal($loan);
            }

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  6. PRE-CLOSE LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Pre-close a loan: enforce lock period and minimum interest, then pay full outstanding.
     */
    public function preCloseLoan(DhiranLoan $loan, string $method = 'cash'): ?DhiranPayment
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Only active loans can be pre-closed.');
        }
        if (! $loan->canPreClose()) {
            throw new LogicException('Loan cannot be pre-closed during the lock period.');
        }

        // Accrue interest up to today
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $method): ?DhiranPayment {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            // ── Enforce minimum interest ─────────────────────
            $minimumInterest = $loan->minimumInterestAmount();
            $totalInterestCollected = (float) $loan->total_interest_collected + (float) $loan->outstanding_interest;

            if ($totalInterestCollected < $minimumInterest) {
                $shortfall = round($minimumInterest - $totalInterestCollected, 2);
                $loan->outstanding_interest = round((float) $loan->outstanding_interest + $shortfall, 2);
                $loan->save();

                // Ledger entry for minimum interest adjustment
                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'interest_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $shortfall,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => (float) $loan->outstanding_interest,
                    'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                    'note'                    => "Minimum interest enforcement: shortfall {$shortfall}",
                    'meta'                    => json_encode([
                        'min_interest_months' => $loan->min_interest_months,
                        'minimum_amount'      => $minimumInterest,
                        'shortfall'           => $shortfall,
                    ]),
                ]);
            }

            // ── Pay full outstanding ─────────────────────────
            $totalOutstanding = $loan->totalOutstanding();

            if ($totalOutstanding <= 0) {
                // Already fully paid — just close without a phantom payment row.
                $this->closeLoanInternal($loan);

                return null;
            }

            $remaining = $totalOutstanding;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);
            $remaining -= $interestComponent;

            $principalComponent = min($remaining, (float) $loan->outstanding_principal);

            // ── Update loan ──────────────────────────────────
            $loan->outstanding_penalty    = 0;
            $loan->outstanding_interest   = 0;
            $loan->outstanding_principal  = 0;
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->total_principal_collected = round((float) $loan->total_principal_collected + $principalComponent, 2);
            $loan->save();

            // ── Payment ──────────────────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => 'pre_closure',
                'amount'                     => $totalOutstanding,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => round($principalComponent, 2),
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => 0,
                'outstanding_interest_after' => 0,
                'outstanding_penalty_after'  => 0,
                'notes'                      => "Pre-closure: {$loan->loan_number}",
                'created_by'                 => $loan->created_by,
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $totalOutstanding,
                'source_type'        => 'pre_closure',
                'payment_method'     => $method,
                'description'        => "Pre-closure: {$loan->loan_number}",
                'created_by'         => $loan->created_by,
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'pre_closure',
                'direction'               => 'credit',
                'amount'                  => $totalOutstanding,
                'balance_after'           => 0,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Pre-closure completed: {$loan->loan_number}",
                'created_by'              => $loan->created_by,
            ]);

            // ── Close the loan ───────────────────────────────
            $this->closeLoanInternal($loan);

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  7. RENEW LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Renew a loan: pay outstanding interest+penalty, mark old loan renewed,
     * create new loan with same collateral. No new cash disbursement.
     *
     * Note: gold_rate_on_date / silver_rate_on_date on the new loan are copied
     * from the old loan by design. Renewal is not a re-pledge — LTV is already
     * satisfied by the original collateral valuation and we never re-value on
     * renewal. If market rates must be refreshed, close and re-issue instead.
     */
    public function renewLoan(DhiranLoan $loan, ?int $newTenure = null, ?float $newRate = null): DhiranLoan
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Only active loans can be renewed.');
        }

        // Accrue interest up to today
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $newTenure, $newRate): DhiranLoan {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            // Enforce minimum interest on renewal (same rule as pre-close)
            // so a same-day renewal cannot bypass min_interest_months.
            $minimumInterest = $loan->minimumInterestAmount();
            $totalInterestCollected = (float) $loan->total_interest_collected + (float) $loan->outstanding_interest;

            if ($totalInterestCollected < $minimumInterest) {
                $shortfall = round($minimumInterest - $totalInterestCollected, 2);
                $loan->outstanding_interest = round((float) $loan->outstanding_interest + $shortfall, 2);
                $loan->save();

                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'interest_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $shortfall,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => (float) $loan->outstanding_interest,
                    'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                    'note'                    => "Minimum interest enforcement (renewal): shortfall {$shortfall}",
                    'meta'                    => json_encode([
                        'min_interest_months' => $loan->min_interest_months,
                        'minimum_amount'      => $minimumInterest,
                        'shortfall'           => $shortfall,
                    ]),
                ]);
            }

            // ── Outstanding interest + penalty must be zero ──
            $interestAndPenalty = round((float) $loan->outstanding_interest + (float) $loan->outstanding_penalty, 2);

            if ($interestAndPenalty > 0) {
                // Auto-pay interest+penalty via recordInterestPayment
                // We need to do this inside the transaction
                $remaining = $interestAndPenalty;

                $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
                $remaining -= $penaltyComponent;
                $interestComponent = min($remaining, (float) $loan->outstanding_interest);

                $loan->outstanding_penalty    = 0;
                $loan->outstanding_interest   = 0;
                $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
                $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
                $loan->save();

                $renewalPayment = DhiranPayment::record([
                    'shop_id'                    => $loan->shop_id,
                    'dhiran_loan_id'             => $loan->id,
                    'payment_date'               => today()->toDateString(),
                    'type'                       => 'renewal_interest',
                    'amount'                     => $interestAndPenalty,
                    'direction'                  => 'in',
                    'payment_method'             => 'cash',
                    'interest_component'         => round($interestComponent, 2),
                    'penalty_component'          => round($penaltyComponent, 2),
                    'principal_component'        => 0,
                    'processing_fee_component'   => 0,
                    'outstanding_principal_after' => (float) $loan->outstanding_principal,
                    'outstanding_interest_after' => 0,
                    'outstanding_penalty_after'  => 0,
                    'notes'                      => "Interest cleared for renewal: {$loan->loan_number}",
                    'created_by'                 => $loan->created_by,
                ]);

                DhiranCashEntry::record([
                    'shop_id'            => $loan->shop_id,
                    'dhiran_loan_id'     => $loan->id,
                    'dhiran_payment_id'  => $renewalPayment->id,
                    'entry_date'         => today()->toDateString(),
                    'type'               => 'in',
                    'amount'             => $interestAndPenalty,
                    'source_type'        => 'interest_collection',
                    'payment_method'     => 'cash',
                    'description'        => "Renewal interest cleared: {$loan->loan_number}",
                    'created_by'         => $loan->created_by,
                ]);

                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'dhiran_payment_id'       => $renewalPayment->id,
                    'entry_type'              => 'interest_collection',
                    'direction'               => 'credit',
                    'amount'                  => $interestAndPenalty,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => 0,
                    'penalty_balance_after'   => 0,
                    'note'                    => "Interest+penalty cleared for renewal",
                    'created_by'              => $loan->created_by,
                ]);
            }

            // ── Validate renewal principal against loan limits ──
            // Renewal bypasses createLoan(), so replicate its min/max guard
            // here to prevent a largely-paid-down loan from renewing below
            // the configured floor, or a settings change to max from being
            // bypassed. Throwing inside the closure ensures a clean rollback.
            $oldPrincipal = (float) $loan->outstanding_principal;
            $settings     = DhiranSettings::getForShop($loan->shop_id);

            if ($oldPrincipal < (float) $settings->min_loan_amount) {
                throw new LogicException(
                    "Renewal principal ({$oldPrincipal}) is below minimum loan amount ({$settings->min_loan_amount}). Close and re-issue instead."
                );
            }
            if ($oldPrincipal > (float) $settings->max_loan_amount) {
                throw new LogicException(
                    "Renewal principal ({$oldPrincipal}) exceeds maximum loan amount ({$settings->max_loan_amount})."
                );
            }

            // ── Re-validate LTV ceiling against current settings ──
            // A settings change to default_ltv_ratio / high_value_ltv_ratio /
            // high_value_threshold between origination and renewal can push a
            // previously-valid principal above the current ceiling. We do NOT
            // re-price collateral (market_value and rate_per_gram_at_pledge are
            // carried forward by design, per the class docblock) — we only
            // re-apply the ratio to the existing market_value of still-pledged
            // items. If the old principal now breaches the ceiling, force a
            // partial repayment before allowing renewal.
            $pledgedItems = $loan->items()->where('status', 'pledged')->get();
            $totalMarketValue = round(
                (float) $pledgedItems->sum(fn ($i) => (float) $i->market_value),
                2
            );

            if ($totalMarketValue >= (float) $settings->high_value_threshold) {
                $ltvRatio = (float) $settings->high_value_ltv_ratio;
            } else {
                $ltvRatio = (float) $settings->default_ltv_ratio;
            }

            $newMaxPrincipal = round($totalMarketValue * $ltvRatio / 100, 2);

            if ($oldPrincipal > $newMaxPrincipal) {
                throw new LogicException(
                    "Renewal principal ({$oldPrincipal}) exceeds current LTV ceiling ({$newMaxPrincipal}) for market value {$totalMarketValue} at {$ltvRatio}% LTV. Partial repayment required before renewal."
                );
            }

            // Recompute each pledged item's loan_value at the current ratio so
            // the transferred items on the new loan reflect current policy.
            // market_value and rate_per_gram_at_pledge remain untouched.
            foreach ($pledgedItems as $pledgedItem) {
                $pledgedItem->loan_value = round((float) $pledgedItem->market_value * $ltvRatio / 100, 2);
                $pledgedItem->save();
            }

            // ── Mark old loan as renewed ─────────────────────
            $loan->status        = 'renewed';
            $loan->renewed_count = (int) $loan->renewed_count + 1;
            $loan->closed_at     = now();
            $loan->closure_notes = 'Renewed';
            $loan->save();

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'entry_type'              => 'renewal',
                'direction'               => 'credit',
                'amount'                  => $oldPrincipal,
                'balance_after'           => 0,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Loan renewed: {$loan->loan_number}",
                'created_by'              => $loan->created_by,
            ]);

            // ── Create new loan ──────────────────────────────
            // $settings was already fetched above for the min/max validation.
            $shop           = Shop::findOrFail($loan->shop_id);
            $newLoanNumber  = $this->generateLoanNumber($shop);
            $newTenureVal   = $newTenure ?? (int) $loan->tenure_months;
            $newRateVal     = $newRate ?? (float) $loan->interest_rate_monthly;
            $newLoanDate    = today();
            $newMaturity    = $newLoanDate->copy()->addMonths($newTenureVal);

            $newLoan = DhiranLoan::create([
                'shop_id'                  => $loan->shop_id,
                'customer_id'              => $loan->customer_id,
                'loan_number'              => $newLoanNumber,
                'loan_date'                => $newLoanDate->toDateString(),
                'gold_rate_on_date'        => $loan->gold_rate_on_date,
                'silver_rate_on_date'      => $loan->silver_rate_on_date,
                'principal_amount'         => $oldPrincipal,
                'processing_fee'           => 0,
                'processing_fee_type'      => 'flat',
                'interest_rate_monthly'    => $newRateVal,
                'interest_type'            => $loan->interest_type,
                'penalty_rate_monthly'     => (float) $loan->penalty_rate_monthly,
                // Use the freshly re-validated LTV ratio so the new loan row
                // matches the current policy snapshot (and the per-item
                // loan_value values re-computed just above).
                'ltv_ratio_applied'        => $ltvRatio,
                'total_collateral_value'   => (float) $loan->total_collateral_value,
                'total_fine_weight'        => (float) $loan->total_fine_weight,
                'outstanding_principal'    => $oldPrincipal,
                'outstanding_interest'     => 0,
                'outstanding_penalty'      => 0,
                'interest_accrued_through' => $newLoanDate->toDateString(),
                'total_interest_collected' => 0,
                'total_penalty_collected'  => 0,
                'total_principal_collected' => 0,
                'tenure_months'            => $newTenureVal,
                'maturity_date'            => $newMaturity->toDateString(),
                'min_lock_months'          => (int) $loan->min_lock_months,
                'grace_period_days'        => (int) $loan->grace_period_days,
                'min_interest_months'      => (int) $loan->min_interest_months,
                'status'                   => 'active',
                'renewed_count'            => 0,
                'renewed_from_id'          => $loan->id,
                'kyc_aadhaar'              => $loan->kyc_aadhaar,
                'kyc_pan'                  => $loan->kyc_pan,
                'kyc_photo_path'           => $loan->kyc_photo_path,
                'terms_text'               => $loan->terms_text,
                'notes'                    => "Renewed from {$loan->loan_number}",
                'created_by'               => auth()->id() ?? $loan->created_by,
            ]);

            // ── Transfer pledged items to new loan ───────────
            $loan->items()->where('status', 'pledged')->update([
                'dhiran_loan_id' => $newLoan->id,
            ]);

            // ── Ledger entry for new loan (no cash disbursement) ─
            DhiranLedgerEntry::record([
                'shop_id'                 => $newLoan->shop_id,
                'dhiran_loan_id'          => $newLoan->id,
                'entry_type'              => 'renewal',
                'direction'               => 'debit',
                'amount'                  => $oldPrincipal,
                'balance_after'           => $oldPrincipal,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Renewed from {$loan->loan_number}",
                'meta'                    => json_encode([
                    'renewed_from_id'     => $loan->id,
                    'renewed_from_number' => $loan->loan_number,
                ]),
                'created_by'              => $loan->created_by,
            ]);

            return $newLoan->fresh(['items']);
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  8. CLOSE LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Close a loan. Validates zero outstanding balance.
     */
    public function closeLoan(DhiranLoan $loan): void
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Only active loans can be closed.');
        }

        if ($loan->totalOutstanding() > 0) {
            throw new LogicException(
                'Cannot close loan with outstanding balance of ' . $loan->totalOutstanding() . '. Pay in full first.'
            );
        }

        DB::transaction(function () use ($loan): void {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);
            $this->closeLoanInternal($loan);
        });
    }

    /**
     * Internal close — assumes caller is inside a transaction and has validated preconditions.
     */
    private function closeLoanInternal(DhiranLoan $loan): void
    {
        // Capture actor once — auth()->id() in web context, falling back to
        // the loan's original creator for CLI/background invocations.
        $actorId = auth()->id() ?? $loan->created_by;

        // Release all remaining pledged items. released_by must be set for
        // audit consistency with the per-item releaseItem() path (see M5 fix).
        $loan->items()->where('status', 'pledged')->update([
            'status'      => 'released',
            'released_at' => now(),
            'released_by' => $actorId,
        ]);

        $loan->status    = 'closed';
        $loan->closed_at = now();
        $loan->save();

        DhiranLedgerEntry::record([
            'shop_id'                 => $loan->shop_id,
            'dhiran_loan_id'          => $loan->id,
            'entry_type'              => 'closure',
            'direction'               => 'credit',
            'amount'                  => 0,
            'balance_after'           => 0,
            'interest_balance_after'  => 0,
            'penalty_balance_after'   => 0,
            'note'                    => "Loan closed: {$loan->loan_number}",
            'created_by'              => $actorId,
        ]);
    }

    /* ══════════════════════════════════════════════════════════
     *  9. SEND FORFEITURE NOTICE
     * ══════════════════════════════════════════════════════════ */

    /**
     * Send forfeiture notice to borrower (RBI 30-day notice requirement).
     */
    public function sendForfeitureNotice(DhiranLoan $loan): void
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Forfeiture notice can only be sent for active loans.');
        }

        if (! $loan->isOverdue()) {
            throw new LogicException('Forfeiture notice can only be sent for overdue loans.');
        }

        // Validate overdue past grace period
        $gracePeriodEnd = Carbon::parse($loan->maturity_date)->addDays($loan->grace_period_days);
        if (today()->lte($gracePeriodEnd)) {
            throw new LogicException('Loan is still within the grace period. Cannot send forfeiture notice yet.');
        }

        DB::transaction(function () use ($loan): void {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            $settings = DhiranSettings::getForShop($loan->shop_id);

            $noticeText = "FORFEITURE NOTICE\n\n"
                . "Loan Number: {$loan->loan_number}\n"
                . "Loan Date: {$loan->loan_date->format('d-M-Y')}\n"
                . "Principal Amount: {$loan->principal_amount}\n"
                . "Outstanding Balance: {$loan->totalOutstanding()}\n"
                . "Maturity Date: {$loan->maturity_date->format('d-M-Y')}\n"
                . "Days Overdue: {$loan->daysOverdue()}\n\n"
                . "This is to inform you that your gold loan is overdue. "
                . "If the outstanding amount is not settled within {$settings->forfeiture_notice_days} days "
                . "from the date of this notice, the pledged items will be forfeited.\n\n"
                . "Date of Notice: " . today()->format('d-M-Y');

            $loan->forfeiture_notice_sent_at = now();
            $loan->forfeiture_notice_text    = $noticeText;
            $loan->save();
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  10. EXECUTE FORFEITURE
     * ══════════════════════════════════════════════════════════ */

    /**
     * Execute forfeiture: validate notice period elapsed, forfeit items, write off balances.
     */
    public function executeForfeit(DhiranLoan $loan): void
    {
        if ($loan->status !== 'active') {
            throw new LogicException('Only active loans can be forfeited.');
        }

        if (! $loan->forfeiture_notice_sent_at) {
            throw new LogicException('Forfeiture notice must be sent before executing forfeiture.');
        }

        $settings = DhiranSettings::getForShop($loan->shop_id);

        $noticeSentAt = Carbon::parse($loan->forfeiture_notice_sent_at);
        $requiredDate = $noticeSentAt->copy()->addDays($settings->forfeiture_notice_days);

        if (today()->lt($requiredDate)) {
            $daysRemaining = (int) today()->diffInDays($requiredDate, false);
            throw new LogicException(
                "Forfeiture notice period has not elapsed. {$daysRemaining} days remaining (required: {$settings->forfeiture_notice_days} days)."
            );
        }

        $actorId = auth()->id() ?? $loan->created_by;

        DB::transaction(function () use ($loan, $actorId): void {
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);

            $writtenOffPrincipal = (float) $loan->outstanding_principal;
            $writtenOffInterest  = (float) $loan->outstanding_interest;
            $writtenOffPenalty   = (float) $loan->outstanding_penalty;
            $totalWrittenOff     = round($writtenOffPrincipal + $writtenOffInterest + $writtenOffPenalty, 2);

            // ── Mark all pledged items as forfeited ──────────
            $loan->items()->where('status', 'pledged')->update([
                'status'       => 'forfeited',
                'forfeited_at' => now(),
            ]);

            // ── Write off all outstanding balances ───────────
            $loan->outstanding_principal = 0;
            $loan->outstanding_interest  = 0;
            $loan->outstanding_penalty   = 0;
            $loan->status                = 'forfeited';
            $loan->forfeited_at          = now();
            $loan->forfeited_by          = $actorId;
            $loan->save();

            // ── Ledger entry for forfeiture ──────────────────
            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'entry_type'              => 'forfeiture',
                'direction'               => 'credit',
                'amount'                  => $totalWrittenOff,
                'balance_after'           => 0,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Forfeiture executed: wrote off P:{$writtenOffPrincipal} I:{$writtenOffInterest} Pen:{$writtenOffPenalty}",
                'meta'                    => json_encode([
                    'written_off_principal' => $writtenOffPrincipal,
                    'written_off_interest'  => $writtenOffInterest,
                    'written_off_penalty'   => $writtenOffPenalty,
                ]),
                'created_by'              => $actorId,
            ]);
        });

        $loan->refresh();
    }

    /* ══════════════════════════════════════════════════════════
     *  11. LOAN SUMMARY
     * ══════════════════════════════════════════════════════════ */

    /**
     * Full computed snapshot of a loan's current state.
     *
     * WARNING — not a pure read: when the loan is active, this method calls
     * accrueInterest(), which writes to dhiran_loans (updates
     * interest_accrued_through, outstanding_interest, outstanding_penalty)
     * and posts interest/penalty accrual rows to the ledger. Any HTTP GET
     * endpoint that invokes loanSummary() therefore mutates state, so it
     * MUST NOT be routed to a read-replica — callers on replicas must either
     * take the "stale" branch or bypass this method.
     */
    public function loanSummary(DhiranLoan $loan): array
    {
        // Accrue interest to get up-to-date numbers (only if active)
        if ($loan->status === 'active') {
            $this->accrueInterest($loan);
            $loan->refresh();
        }

        $loan->loadMissing(['items', 'payments', 'customer']);

        $pledgedItems  = $loan->items->where('status', 'pledged');
        $releasedItems = $loan->items->where('status', 'released');
        $forfeitedItems = $loan->items->where('status', 'forfeited');

        return [
            'loan_id'                  => $loan->id,
            'loan_number'              => $loan->loan_number,
            'status'                   => $loan->status,
            'customer_name'            => $loan->customer->name ?? null,
            'customer_id'              => $loan->customer_id,
            'loan_date'                => $loan->loan_date->toDateString(),
            'maturity_date'            => $loan->maturity_date->toDateString(),
            'tenure_months'            => $loan->tenure_months,
            'principal_amount'         => (float) $loan->principal_amount,
            'interest_rate_monthly'    => (float) $loan->interest_rate_monthly,
            'interest_type'            => $loan->interest_type,
            'penalty_rate_monthly'     => (float) $loan->penalty_rate_monthly,
            'ltv_ratio_applied'        => (float) $loan->ltv_ratio_applied,
            'total_collateral_value'   => (float) $loan->total_collateral_value,
            'total_fine_weight'        => (float) $loan->total_fine_weight,
            'outstanding_principal'    => (float) $loan->outstanding_principal,
            'outstanding_interest'     => (float) $loan->outstanding_interest,
            'outstanding_penalty'      => (float) $loan->outstanding_penalty,
            'total_outstanding'        => $loan->totalOutstanding(),
            'total_interest_collected' => (float) $loan->total_interest_collected,
            'total_penalty_collected'  => (float) $loan->total_penalty_collected,
            'total_principal_collected' => (float) $loan->total_principal_collected,
            'processing_fee'           => (float) $loan->processing_fee,
            'is_overdue'               => $loan->isOverdue(),
            'days_overdue'             => $loan->daysOverdue(),
            'days_till_maturity'       => $loan->daysTillMaturity(),
            'is_in_lock_period'        => $loan->isInLockPeriod(),
            'can_pre_close'            => $loan->canPreClose(),
            'minimum_interest_amount'  => $loan->minimumInterestAmount(),
            'renewed_count'            => (int) $loan->renewed_count,
            'renewed_from_id'          => $loan->renewed_from_id,
            'items_count'              => $loan->items->count(),
            'pledged_items_count'      => $pledgedItems->count(),
            'released_items_count'     => $releasedItems->count(),
            'forfeited_items_count'    => $forfeitedItems->count(),
            'payments_count'           => $loan->payments->count(),
            'interest_accrued_through' => $loan->interest_accrued_through
                ? Carbon::parse($loan->interest_accrued_through)->toDateString()
                : null,
            'closed_at'                => $loan->closed_at?->toDateTimeString(),
            'forfeited_at'             => $loan->forfeited_at?->toDateTimeString(),
            'forfeiture_notice_sent_at' => $loan->forfeiture_notice_sent_at?->toDateTimeString(),
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  12. BATCH INTEREST ACCRUAL
     * ══════════════════════════════════════════════════════════ */

    /**
     * Accrue interest on all active loans for a shop. Returns count processed.
     */
    public function accrueInterestBatch(int $shopId): int
    {
        $loans = DhiranLoan::where('shop_id', $shopId)
            ->where('status', 'active')
            ->get();

        $count = 0;

        foreach ($loans as $loan) {
            $this->accrueInterest($loan);
            $count++;
        }

        return $count;
    }

    /* ══════════════════════════════════════════════════════════
     *  13. CUSTOMER LOAN HISTORY
     * ══════════════════════════════════════════════════════════ */

    /**
     * Get all loans for a customer, optionally filtered by status.
     */
    public function customerLoanHistory(Customer $customer, ?string $status = null): Collection
    {
        $query = DhiranLoan::where('customer_id', $customer->id)
            ->where('shop_id', $customer->shop_id);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->with(['items'])
            ->orderByDesc('loan_date')
            ->orderByDesc('id')
            ->get();
    }

    /* ══════════════════════════════════════════════════════════
     *  14. GET OVERDUE LOANS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Active loans past maturity date.
     */
    public function getOverdueLoans(int $shopId): Collection
    {
        return DhiranLoan::where('shop_id', $shopId)
            ->overdue()
            ->with(['customer', 'items'])
            ->orderBy('maturity_date')
            ->get();
    }

    /* ══════════════════════════════════════════════════════════
     *  15. GET DEFAULT RISK LOANS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Loans past maturity + grace period — at risk of forfeiture.
     */
    public function getDefaultRiskLoans(int $shopId): Collection
    {
        return DhiranLoan::where('shop_id', $shopId)
            ->defaultRisk()
            ->with(['customer', 'items'])
            ->orderBy('maturity_date')
            ->get();
    }

    /* ══════════════════════════════════════════════════════════
     *  16. GET UNPROFITABLE LOANS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Loans where total interest collected is less than expected minimum.
     * "Expected" = principal * rate * months_elapsed / 100
     */
    public function getUnprofitableLoans(int $shopId): Collection
    {
        return DhiranLoan::where('shop_id', $shopId)
            ->where('status', 'active')
            ->with(['customer'])
            ->get()
            ->filter(function (DhiranLoan $loan): bool {
                $loanDate = Carbon::parse($loan->loan_date);
                $monthsElapsed = max(1, (int) ceil($loanDate->diffInDays(today()) / 30));
                $expectedInterest = (float) $loan->principal_amount
                    * (float) $loan->interest_rate_monthly / 100
                    * $monthsElapsed;

                $actualCollected = (float) $loan->total_interest_collected;

                return $actualCollected < $expectedInterest;
            })
            ->values();
    }

    /* ══════════════════════════════════════════════════════════
     *  17. GENERATE LOAN NUMBER
     * ══════════════════════════════════════════════════════════ */

    /**
     * Generate the next loan number using BusinessIdentifierService pattern
     * with ShopCounter and lockForUpdate for concurrency safety.
     */
    public function generateLoanNumber(Shop $shop): string
    {
        // Concurrency-safe even when invoked outside createLoan's transaction:
        // nextCounter() opens its own DB::transaction with lockForUpdate on the
        // ShopCounter row, and dhiran_loans has a UNIQUE(shop_id, loan_number)
        // constraint as a belt-and-braces safeguard against duplicates.
        $settings = DhiranSettings::getForShop($shop->id);
        $prefix   = trim($settings->loan_number_prefix ?: 'DH-');

        $sequence = BusinessIdentifierService::nextCounter($shop->id, BusinessIdentifierService::KEY_DHIRAN);

        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
