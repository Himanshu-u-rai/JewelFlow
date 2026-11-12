<?php

namespace App\Services\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Rules\IndianMobileRule;
use App\Rules\PanFormatRule;
use App\Services\DhiranService;
use App\Services\Historical\HistoricalSourceFileReader;
use App\Support\Historical\HistoricalParseException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Old Loans (owner 2026-11-12): bringing a shop's running paper loans in, from the form or a spreadsheet. One set of
 * rules for both; the loan comes in as it stands today (DhiranService::createLoan, brought_forward).
 */
final class DhiranOldLoans
{
    /** Spreadsheet columns, in template order: one row per pledged item, rows sharing a ticket number are one loan. */
    public const COLUMNS = [
        'ticket_number' => 'Ticket number in your book', 'loan_date' => 'Date the loan was given', 'customer_name' => 'Customer name',
        'mobile' => 'Mobile', 'address' => 'Address', 'pan' => 'PAN', 'id_number' => 'ID number', 'amount_lent' => 'Amount lent',
        'principal_owed' => 'Principal still owed', 'interest_rate_monthly' => 'Interest % per month', 'interest_type' => 'flat / daily / compound',
        'tenure_months' => 'Tenure (months)', 'interest_paid_up_to' => 'Interest paid up to', 'unpaid_interest' => 'Unpaid interest (optional)',
        'item_description' => 'Item', 'metal' => 'gold / silver / other', 'purity' => 'Purity (gold 22, silver 925)', 'gross_weight_g' => 'Gross weight (g)',
        'stone_weight_g' => 'Stone weight (g)', 'rate_per_gram' => 'Rate per gram', 'appraised_value' => 'Appraised value (other items)',
    ];

    public const MAX_ROWS = 2000;

    /** Readable names in error messages. */
    private const NAMES = [
        'paper_loan_number' => 'ticket number', 'loan_date' => 'loan date', 'principal_amount' => 'amount lent',
        'principal_owed' => 'principal still owed', 'interest_rate_monthly' => 'interest rate', 'interest_type' => 'interest type',
        'tenure_months' => 'tenure', 'interest_paid_until' => 'interest paid up to', 'unpaid_interest' => 'unpaid interest',
        'borrower.first_name' => 'customer name', 'borrower.mobile' => 'mobile', 'borrower.pan' => 'PAN', 'items' => 'pledged items',
        'gold_rate_on_date' => 'gold rate', 'silver_rate_on_date' => 'silver rate',
    ];

    public function __construct(private DhiranService $dhiranService) {}

    public static function rules(array $items, int $shopId): array
    {
        return DhiranCollateral::rules() + [
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
        ];
    }

    /** Problems with one old loan's data, keyed like validation errors (empty when it can come in). */
    public static function problems(array $data, int $shopId): array
    {
        $v = Validator::make($data, self::rules($data['items'] ?? [], $shopId), [], self::NAMES);
        $errors = $v->fails() ? array_map(fn ($m) => $m[0], $v->errors()->toArray()) : [];

        return $errors ?: DhiranCollateral::errors($data['items']);
    }

    /** Borrower (picked, already on the books by mobile, or new) and the loan as it stands today, in one transaction. */
    public function bringIn(array $data, int $shopId): DhiranLoan
    {
        return DB::transaction(function () use ($data, $shopId) {
            $customer = $this->borrower($data);

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
    }

    private function borrower(array $data): Customer
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
            'pan'        => ! empty($b['pan']) ? strtoupper(trim($b['pan'])) : null,
            'id_number'  => $b['id_number'] ?? null,
        ]);
    }

    /** The template: the header row and two example rows (one loan with two items). */
    public static function template(): string
    {
        $rows = [array_keys(self::COLUMNS),
            ['778', '15-01-2026', 'Asha Rao', '9810000001', 'Market Road', '', '', '100000', '60000', '2', 'flat', '12', '15-10-2026', '', 'Gold chain', 'gold', '22', '40', '0', '6000', ''],
            ['778', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Gold ring', 'gold', '22', '10', '0.5', '6000', ''],
        ];

        return implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => str_contains($v, ',') ? "\"{$v}\"" : $v, $r)), $rows)) . "\n";
    }

    /**
     * The loans in a spreadsheet, each with the rows it came from and its problems.
     *
     * @return array<int, array{ticket: string, rows: array<int, int>, data: array, problems: array<int, string>}>
     *
     * @throws HistoricalParseException
     */
    public function read(string $path, string $ext, int $shopId): array
    {
        $reader = app(HistoricalSourceFileReader::class);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $reader->headers($path, $ext, null, 1));
        if ($missing = array_diff(array_keys(self::COLUMNS), $headers)) {
            throw new HistoricalParseException('This file is not the Old Loans template. Missing columns: ' . implode(', ', $missing) . '.');
        }

        $tickets = [];
        $count = 0;
        foreach ($reader->rows($path, $ext, null, 1, $headers) as ['row_number' => $n, 'cells' => $c]) {
            if (++$count > self::MAX_ROWS) {
                throw new HistoricalParseException('Up to ' . self::MAX_ROWS . ' rows at a time. Split the file and upload each part.');
            }
            $ticket = trim((string) ($c['ticket_number'] ?? ''));
            $tickets[$ticket === '' ? "(no ticket, row {$n})" : $ticket][] = [$n, $c];
        }

        $out = [];
        foreach ($tickets as $ticket => $rows) {
            $data = $this->loanFromRows($ticket, $rows);
            $problems = [];
            foreach (self::problems($data, $shopId) as $key => $message) {
                // An item's problem names its own row; the loan's problems name the ticket's first row.
                $row = preg_match('/^items\.(\d+)\./', $key, $m) ? ($data['_item_rows'][$m[1]] ?? $rows[0][0]) : $rows[0][0];
                $problems[] = "Row {$row}: {$message}";
            }
            $out[] = ['ticket' => $ticket, 'rows' => array_column($rows, 0), 'data' => $data, 'problems' => $problems];
        }

        return $out;
    }

    /** One loan from its rows: loan details from the ticket's first row, one item per row that names an item. */
    private function loanFromRows(string $ticket, array $rows): array
    {
        $c = $rows[0][1];
        $name = preg_split('/\s+/', trim((string) ($c['customer_name'] ?? '')), 2);
        $items = [];
        $itemRows = [];
        foreach ($rows as [$n, $r]) {
            if (trim((string) ($r['item_description'] ?? '')) === '') {
                continue;
            }
            $metal = strtolower(trim((string) ($r['metal'] ?? ''))) ?: 'gold';
            $rate = self::number($r['rate_per_gram'] ?? null);
            $appraised = self::number($r['appraised_value'] ?? null);
            $items[] = [
                'description'             => trim((string) $r['item_description']),
                'metal_type'              => $metal,
                'value_mode'              => $metal === 'other' || ($appraised !== null && $rate === null) ? 'appraised' : 'metal',
                'purity'                  => self::number($r['purity'] ?? null),
                'gross_weight'            => self::number($r['gross_weight_g'] ?? null),
                'stone_weight'            => self::number($r['stone_weight_g'] ?? null) ?? 0,
                'rate_per_gram_at_pledge' => $rate,
                'market_value'            => $appraised,
            ];
            $itemRows[] = $n;
        }
        $rateFor = fn (string $metal) => collect($items)->first(fn ($i) => $i['metal_type'] === $metal && $i['value_mode'] === 'metal')['rate_per_gram_at_pledge'] ?? null;

        return [
            'customer_id'           => null,
            'borrower'              => [
                'first_name' => ($name[0] ?? '') !== '' ? $name[0] : null,
                'last_name'  => $name[1] ?? null,
                'mobile'     => self::text($c['mobile'] ?? null),
                'address'    => self::text($c['address'] ?? null),
                'pan'        => self::text($c['pan'] ?? null),
                'id_number'  => self::text($c['id_number'] ?? null),
            ],
            'paper_loan_number'     => $ticket,
            'loan_date'             => self::date($c['loan_date'] ?? null),
            'principal_amount'      => self::number($c['amount_lent'] ?? null),
            'principal_owed'        => self::number($c['principal_owed'] ?? null),
            'interest_rate_monthly' => self::number($c['interest_rate_monthly'] ?? null),
            'interest_type'         => strtolower(self::text($c['interest_type'] ?? null) ?? 'flat'),
            'tenure_months'         => self::number($c['tenure_months'] ?? null),
            'interest_paid_until'   => self::date($c['interest_paid_up_to'] ?? null),
            'unpaid_interest'       => self::number($c['unpaid_interest'] ?? null),
            'gold_rate_on_date'     => $rateFor('gold'),
            'silver_rate_on_date'   => $rateFor('silver'),
            'items'                 => $items,
            '_item_rows'            => $itemRows,
        ];
    }

    private static function text(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** "1,00,000" and "1,234.50" as numbers; blank as null; anything else left for the validator to refuse. */
    private static function number(mixed $v): mixed
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $v = str_replace([',', ' ', '₹'], '', trim((string) $v));

        return $v === '' ? null : (is_numeric($v) ? $v + 0 : $v);
    }

    /** Dates as the paper book writes them (15-01-2026, 15/01/2026, 2026-01-15) or an Excel date cell. */
    private static function date(mixed $v): ?string
    {
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', trim($v)))) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-m-y', 'd/m/y'] as $format) {
            $d = \DateTime::createFromFormat('!' . $format, $v);
            if ($d && $d->format($format) === $v) {
                return $d->format('Y-m-d');
            }
        }

        return $v; // the validator says it is not a date
    }
}
