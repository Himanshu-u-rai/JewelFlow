<?php

namespace Tests\Concurrency;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * S3-07b — fixture + reporting side of the REAL concurrency harness.
 *
 * WHY THIS EXISTS. The PHPUnit suite cannot schedule a genuine race: under
 * `RefreshDatabase` every test body runs inside one uncommitted transaction, so
 * a second connection cannot see the fixtures the test created. That is a real
 * limitation of the suite — but it is NOT evidence that the race is untestable.
 * It only means the race cannot be run *from inside a transaction-wrapped test*.
 *
 * So this command commits synthetic fixtures normally, a shell runner fires
 * overlapping requests from separate OS processes against `php artisan serve`,
 * and this command then reports the committed outcome. Nothing here is a
 * simulation: real HTTP, real processes, real connections, real commits.
 *
 * SAFETY. Every action refuses outright unless the connected database is named
 * `jewelflow_testing`. The command writes real payment and ledger rows, so the
 * guard is on the database name rather than on APP_ENV, which is easy to get
 * wrong from a shell.
 */
class PaymentRaceHarness extends Command
{
    use \Tests\Feature\Traits\CreatesTestTenant;

    protected $signature = 'security:payment-race
        {action : seed|report|cleanup}
        {--invoice= : invoice id, for report}
        {--total=10000 : invoice total, for seed}
        {--users=2 : how many authorized users to mint tokens for}';

    protected $description = 'S3-07b concurrency harness: seed synthetic fixtures, report committed effects, clean up.';

    /** Marker that makes every row this command creates identifiable for cleanup. */
    private const MARKER = 'RACEHARNESS';

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();

        // Hard refusal, before anything else. This command writes payments and
        // ledger rows; it must be impossible to point at a real database by
        // mistake. Name-based, because APP_ENV is the easier thing to get wrong.
        if ($db !== 'jewelflow_testing') {
            $this->error("REFUSED: connected to '{$db}', not 'jewelflow_testing'.");

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed'    => $this->seed(),
            'report'  => $this->report(),
            'cleanup' => $this->cleanup(),
            default   => $this->invalid(),
        };
    }

    private function invalid(): int
    {
        $this->error('action must be one of: seed, report, cleanup');

        return self::FAILURE;
    }

    /**
     * Build a tenant, a finalized invoice and N authorized users with Sanctum
     * tokens, then print the handles the runner needs as JSON on stdout.
     */
    private function seed(): int
    {
        $total = (float) $this->option('total');

        [$owner, $shop] = $this->createRetailerTenant();

        // The route binding and the payment path both read TenantContext, and
        // nothing has set it in a console process.
        TenantContext::set((int) $shop->id);

        $customer = $this->createCustomer((int) $shop->id);

        $invoice = new Invoice();
        // CONSTITUTION Article I — Invoice money columns are guarded, so the
        // fixture uses forceFill exactly as the test-suite helper does rather
        // than inventing a second way to write them.
        $invoice->forceFill([
            'shop_id'        => $shop->id,
            'customer_id'    => $customer->id,
            'invoice_number' => 'INV-'.self::MARKER.'-'.uniqid(),
            'status'         => Invoice::STATUS_FINALIZED,
            'gold_rate'      => 6000,
            'subtotal'       => $total,
            'total'          => $total,
            'gst'            => 0,
            'gst_rate'       => 0,
            'finalized_at'   => now(),
        ])->save();

        $tokens = [$owner->createToken('race-harness')->plainTextToken];

        // Additional authorized users in the SAME shop, for the "two different
        // users, one key" scenario. Same role, so identical permissions.
        for ($i = 1; $i < (int) $this->option('users'); $i++) {
            $role  = Role::withoutTenant()->where('shop_id', $shop->id)->firstOrFail();
            $extra = User::factory()->create([
                'shop_id'   => $shop->id,
                'role_id'   => $role->id,
                'is_active' => true,
            ]);
            $tokens[] = $extra->createToken('race-harness')->plainTextToken;
        }

        $this->line(json_encode([
            'shop_id'    => (int) $shop->id,
            'invoice_id' => (int) $invoice->id,
            'total'      => $total,
            'tokens'     => $tokens,
        ]));

        return self::SUCCESS;
    }

    /**
     * Report the COMMITTED effects for one invoice. Counts and sums only — the
     * runner does the asserting, so this stays a measurement and not a judge.
     */
    private function report(): int
    {
        $invoiceId = (int) $this->option('invoice');

        $invoice = Invoice::withoutTenant()->findOrFail($invoiceId);

        $paid = (float) DB::table('invoice_payments')->where('invoice_id', $invoiceId)->sum('amount');

        $this->line(json_encode([
            'invoice_id'         => $invoiceId,
            'total'              => (float) $invoice->total,
            'payment_count'      => (int) DB::table('invoice_payments')->where('invoice_id', $invoiceId)->count(),
            'paid_total'         => $paid,
            'outstanding'        => round((float) $invoice->total - $paid, 2),
            'claim_count'        => (int) DB::table('invoice_payment_claims')->where('invoice_id', $invoiceId)->count(),
            'cash_txn_count'     => (int) DB::table('cash_transactions')
                ->where('invoice_id', $invoiceId)->count(),
            'audit_count'        => (int) DB::table('audit_logs')
                ->where('model_type', 'invoice')
                ->where('model_id', $invoiceId)
                ->where('action', 'invoice_payment_recorded')
                ->count(),
        ]));

        return self::SUCCESS;
    }

    /**
     * Remove only what this harness created, identified by the invoice-number
     * marker. Unrelated data in jewelflow_testing is left alone, which is why
     * cleanup keys off the marker rather than truncating anything.
     */
    private function cleanup(): int
    {
        $invoiceIds = DB::table('invoices')
            ->where('invoice_number', 'like', 'INV-'.self::MARKER.'-%')
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            $this->line('nothing to clean');

            return self::SUCCESS;
        }

        // Children first — these tables carry FKs to the rows below them.
        DB::table('audit_logs')
            ->where('model_type', 'invoice')
            ->whereIn('model_id', $invoiceIds)
            ->delete();
        DB::table('cash_transactions')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('invoice_payment_claims')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('invoice_payments')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();

        $this->line('cleaned invoices: '.$invoiceIds->implode(','));

        return self::SUCCESS;
    }
}
