<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Rules\IndianMobileRule;
use App\Rules\PanFormatRule;
use App\Services\Dhiran\DhiranCollateral;
use App\Services\Dhiran\DhiranListFilters;
use App\Services\DhiranService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Old Loans (owner 2026-11-12): a shop joining Dhiran brings its running paper loans in, borrower and loan together,
 * as they stand today. Kept apart from the daily New Loan page. Loans come in active (payments from day one); a
 * missing item photo or ID proof is flagged here and on the loan, not blocking. Spreadsheet upload: see upload*.
 */
class DhiranOldLoanController extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array
    {
        return [new \Illuminate\Routing\Controllers\Middleware(\App\Http\Middleware\DhiranSubmitOnce::class, only: ['store'])];
    }

    public function __construct(private DhiranService $dhiranService) {}

    public function index(Request $request)
    {
        $evidence = (string) $request->query('evidence', '');
        $query = DhiranLoan::where('shop_id', auth()->user()->shop_id)->whereRaw('brought_forward is true') // PostgreSQL: no 1 for a boolean
            ->select('dhiran_loans.*')->selectRaw(self::evidenceSql() . ' as evidence_ok');
        if (in_array($evidence, ['missing', 'done'], true)) {
            $query->whereRaw(($evidence === 'missing' ? 'not ' : '') . self::evidenceSql());
        }
        $loans = $query->with('customer')->latest()->orderByDesc('id')
            ->paginate(DhiranListFilters::perPage($request))->withQueryString();

        $filters = ['evidence' => ['legend' => 'Photo and ID proof', 'any' => 'All', 'options' => [
            'missing' => ['Evidence missing', null], 'done' => ['Evidence complete', null],
        ]]];

        return view('dhiran.old-loans.index', compact('loans', 'filters'));
    }

    public function create()
    {
        $shopId = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);
        $customers = Customer::where('shop_id', $shopId)->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'mobile']);

        return view('dhiran.old-loans.create', compact('settings', 'customers'));
    }

    public function store(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;
        $items = (array) $request->input('items', []);

        $data = $request->validate(DhiranCollateral::rules() + [
            'customer_id'            => ['nullable', 'integer', Customer::activeExistsRule($shopId)],
            // A new borrower is needed unless one is picked (the form always sends the picker, empty for "new").
            'borrower'               => ['required_without:customer_id', 'nullable', 'array'],
            'borrower.first_name'    => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'borrower.last_name'     => ['nullable', 'string', 'max:255'],
            'borrower.mobile'        => ['required_without:customer_id', 'nullable', 'string', new IndianMobileRule()],
            'borrower.address'       => ['nullable', 'string', 'max:500'],
            'borrower.pan'           => ['nullable', new PanFormatRule()],
            'borrower.id_number'     => ['nullable', 'string', 'max:50'],
            'paper_loan_number'      => ['required', 'string', 'max:30', Rule::unique('dhiran_loans', 'loan_number')->where('shop_id', $shopId)],
            'loan_date'              => 'required|date|before_or_equal:today',
            'principal_amount'       => 'required|numeric|min:1',
            'principal_owed'         => 'required|numeric|min:0.01|lte:principal_amount',
            'interest_rate_monthly'  => 'required|numeric|min:0|max:10',
            'interest_type'          => 'required|in:flat,daily,compound',
            'tenure_months'          => 'required|integer|min:1|max:60',
            'penalty_rate_monthly'   => 'nullable|numeric|min:0|max:10',
            'grace_period_days'      => 'nullable|integer|min:0|max:90',
            'interest_paid_until'    => 'required|date|after_or_equal:loan_date|before_or_equal:today',
            'unpaid_interest'        => 'nullable|numeric|min:0',
            'gold_rate_on_date'      => (DhiranCollateral::needsRate($items, 'gold') ? 'required' : 'nullable') . '|numeric|min:0',
            'silver_rate_on_date'    => (DhiranCollateral::needsRate($items, 'silver') ? 'required' : 'nullable') . '|numeric|min:0',
            'notes'                  => 'nullable|string|max:1000',
        ]);
        if ($itemErrors = DhiranCollateral::errors($data['items'])) {
            throw ValidationException::withMessages($itemErrors);
        }

        try {
            $loan = DB::transaction(function () use ($data, $shopId) {
                $customer = $this->borrower($data, $shopId);

                return $this->dhiranService->createLoan(auth()->user()->shop, $customer, DhiranCollateral::normalise($data['items']), [
                    'brought_forward'       => true,
                    'status'                => 'active',
                    'loan_number'           => $data['paper_loan_number'],
                    'loan_date'             => $data['loan_date'],
                    'principal_amount'      => $data['principal_amount'],
                    'principal_owed'        => $data['principal_owed'],
                    'interest_paid_until'   => $data['interest_paid_until'],
                    'unpaid_interest'       => $data['unpaid_interest'] ?? null,
                    'interest_rate_monthly' => $data['interest_rate_monthly'],
                    'interest_type'         => $data['interest_type'],
                    'tenure_months'         => $data['tenure_months'],
                    'penalty_rate_monthly'  => $data['penalty_rate_monthly'] ?? null,
                    'grace_period_days'     => $data['grace_period_days'] ?? null,
                    'gold_rate_on_date'     => $data['gold_rate_on_date'] ?? 0,
                    'silver_rate_on_date'   => $data['silver_rate_on_date'] ?? null,
                    'notes'                 => $data['notes'] ?? null,
                    'created_by'            => auth()->id(),
                ]);
            });
        } catch (\LogicException $e) {
            return back()->withErrors(['loan' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dhiran.old-loans.create')
            ->with('success', "Old loan {$loan->loan_number} brought in. Add the next one, or open it from Old Loans.");
    }

    /** The picked borrower, else the one already on the books with that mobile, else a new borrower. */
    private function borrower(array $data, int $shopId): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::findOrFail($data['customer_id']);
        }
        $b = $data['borrower'];

        return Customer::resolveByMobile($b['mobile']) ?? Customer::create([
            'first_name' => $b['first_name'],
            'last_name'  => $b['last_name'] ?? null,
            'mobile'     => $b['mobile'],
            'address'    => $b['address'] ?? null,
            'pan'        => isset($b['pan']) ? strtoupper(trim($b['pan'])) : null,
            'id_number'  => $b['id_number'] ?? null,
        ]);
    }

    /** True when the loan has an item photo and an ID proof (the rule of DhiranService::evidenceStatus, in SQL). */
    public static function evidenceSql(): string
    {
        $loan = DhiranAttachment::OWNER_LOAN;
        $item = DhiranAttachment::OWNER_ITEM;
        $customer = DhiranAttachment::OWNER_CUSTOMER;

        return "(exists (select 1 from dhiran_attachments a where a.document_type = 'item_photo' and ("
            . "(a.owner_type = '{$loan}' and a.owner_id = dhiran_loans.id) or (a.owner_type = '{$item}' and a.owner_id in "
            . "(select i.id from dhiran_loan_items i where i.dhiran_loan_id = dhiran_loans.id))))"
            . " and exists (select 1 from dhiran_attachments a where a.document_type in ('id_proof_front', 'id_proof_back') and ("
            . "(a.owner_type = '{$loan}' and a.owner_id = dhiran_loans.id) or (a.owner_type = '{$customer}' and a.owner_id = dhiran_loans.customer_id))))";
    }
}
