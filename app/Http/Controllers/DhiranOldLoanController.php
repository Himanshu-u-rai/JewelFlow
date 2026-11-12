<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Services\Dhiran\DhiranCollateral;
use App\Services\Dhiran\DhiranListFilters;
use App\Services\Dhiran\DhiranOldLoans;
use App\Support\Historical\HistoricalParseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Old Loans (owner 2026-11-12): a shop joining Dhiran brings its running paper loans in, borrower and loan together,
 * as they stand today, one at a time or from a spreadsheet. Kept apart from the daily New Loan page. Loans come in
 * active (payments from day one); a missing item photo or ID proof is flagged here and on the loan, not blocking.
 */
class DhiranOldLoanController extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array
    {
        return [new \Illuminate\Routing\Controllers\Middleware(\App\Http\Middleware\DhiranSubmitOnce::class, only: ['store', 'confirmUpload'])];
    }

    public function __construct(private DhiranOldLoans $oldLoans) {}

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
        $data = $request->validate(DhiranOldLoans::rules((array) $request->input('items', []), $shopId));
        if ($itemErrors = DhiranCollateral::errors($data['items'])) {
            throw ValidationException::withMessages($itemErrors);
        }

        try {
            $loan = $this->oldLoans->bringIn($data, $shopId);
        } catch (\LogicException $e) {
            return back()->withErrors(['loan' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dhiran.old-loans.create')
            ->with('success', "Old loan {$loan->loan_number} brought in. Add the next one, or open it from Old Loans.");
    }

    /* ── Spreadsheet: template, upload, preview, bring in ─────────────────── */

    public function uploadForm()
    {
        return view('dhiran.old-loans.upload', ['columns' => DhiranOldLoans::COLUMNS, 'maxRows' => DhiranOldLoans::MAX_ROWS]);
    }

    public function template()
    {
        return response()->streamDownload(fn () => print(DhiranOldLoans::template()), 'old-loans-template.csv', ['Content-Type' => 'text/csv']);
    }

    /** Keep the file privately for this shop and show what is in it; nothing is saved to the books yet. */
    public function upload(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']],
            ['file.mimes' => 'Upload the Old Loans template as a CSV or Excel (.xlsx) file.']);
        $ext = strtolower($request->file('file')->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $token = (string) Str::uuid();
        Storage::disk('local')->putFileAs($this->folder(), $request->file('file'), "{$token}.{$ext}");

        return redirect()->route('dhiran.old-loans.upload.preview', $token);
    }

    public function preview(string $token)
    {
        try {
            $loans = $this->oldLoans->read(...$this->file($token));
        } catch (HistoricalParseException $e) {
            return redirect()->route('dhiran.old-loans.upload')->with('error', $e->getMessage());
        }

        return view('dhiran.old-loans.preview', compact('loans', 'token'));
    }

    /** Bring in every loan without problems (each on its own: one failing does not stop the rest); skip the others. */
    public function confirmUpload(string $token)
    {
        try {
            [$path, $ext, $shopId] = $this->file($token);
            $loans = $this->oldLoans->read($path, $ext, $shopId);
        } catch (HistoricalParseException $e) {
            return redirect()->route('dhiran.old-loans.upload')->with('error', $e->getMessage());
        }

        $in = 0;
        $skipped = [];
        foreach ($loans as $loan) {
            if ($loan['problems']) {
                $skipped[] = $loan['ticket'];
                continue;
            }
            try {
                $this->oldLoans->bringIn($loan['data'], $shopId);
                $in++;
            } catch (\LogicException $e) {
                $skipped[] = $loan['ticket'];
            }
        }
        Storage::disk('local')->delete(substr($path, strlen(Storage::disk('local')->path(''))));

        $message = "{$in} old " . Str::plural('loan', $in) . ' brought in' .($skipped ? '; ' . count($skipped) . ' skipped (' . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ', …' : '') . '). Fix those rows and upload them again.' : '.');

        return redirect()->route('dhiran.old-loans.index')->with('success', $message);
    }

    private function folder(): string
    {
        return 'dhiran-old-loans/' . (int) auth()->user()->shop_id;
    }

    /** @return array{0: string, 1: string, 2: int} absolute path, extension, shop — only this shop's own upload */
    private function file(string $token): array
    {
        abort_unless(Str::isUuid($token), 404);
        foreach (['csv', 'xlsx'] as $ext) {
            if (Storage::disk('local')->exists($rel = "{$this->folder()}/{$token}.{$ext}")) {
                return [Storage::disk('local')->path($rel), $ext, (int) auth()->user()->shop_id];
            }
        }
        abort(404);
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
