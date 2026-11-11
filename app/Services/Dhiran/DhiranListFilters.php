<?php

namespace App\Services\Dhiran;

use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
use App\Services\Reporting\Filters\ResolvedPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The filters shared by the Dhiran Loans list and reports (owner 2026-11-08): period presets (months, FY-aligned
 * quarters and the Indian financial year, via the shared FilterResolver), status, metal, principal range, overdue
 * band, maturing soon, search, and cash in/out. Validated here, applied on the server, so a page's totals and
 * page links follow the same filters as its rows.
 */
final class DhiranListFilters
{
    public const PERIODS = [
        '' => 'Any time', 'today' => 'Today', 'this_month' => 'This month', 'last_month' => 'Last month',
        'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter',
        'this_fy' => 'This financial year', 'last_fy' => 'Last financial year', 'custom' => 'Custom dates',
    ];
    public const STATUSES = [
        'pending_evidence' => 'Awaiting evidence', 'active' => 'Active', 'closed' => 'Closed',
        'renewed' => 'Renewed', 'forfeited' => 'Forfeited',
    ];
    public const METALS = ['gold' => 'Gold', 'silver' => 'Silver', 'other' => 'Other'];
    public const OVERDUE = ['1-30' => '1–30 days late', '31-90' => '31–90 days late', '90+' => 'Over 90 days late'];
    public const MATURING = ['7' => 'Due in 7 days', '30' => 'Due in 30 days'];
    public const CASH_TYPES = ['in' => 'Money in', 'out' => 'Money out'];
    public const PER_PAGE = [20, 50, 100];

    /** Rows per page from ?per_page; anything not offered falls back to the default (never "everything"). */
    public static function perPage(Request $request, int $default = 20): int
    {
        $n = (int) $request->query('per_page', (string) $default);

        return in_array($n, self::PER_PAGE, true) ? $n : $default;
    }

    /** @param array<string, string> $values the validated, non-empty filter values */
    private function __construct(public readonly array $values, public readonly ?ResolvedPeriod $period)
    {
    }

    /** Validate the request; $defaultPeriod applies when neither a period nor from/to was given. */
    public static function fromRequest(Request $request, ?string $defaultPeriod = null): self
    {
        $v = $request->validate([
            'period'     => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
            'status'     => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'metal'      => ['nullable', Rule::in(array_keys(self::METALS))],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'min:0'],
            'overdue'    => ['nullable', Rule::in(array_keys(self::OVERDUE))],
            'maturing'   => ['nullable', Rule::in(array_keys(self::MATURING))],
            'cash'       => ['nullable', Rule::in(array_keys(self::CASH_TYPES))], // not "type": the reports hub uses that
            'search'     => ['nullable', 'string', 'max:100'],
        ]);
        $v = array_filter($v, fn ($x) => $x !== null && $x !== '');

        // Old links carry only from/to: treat them as custom dates.
        $preset = $v['period'] ?? (isset($v['from']) || isset($v['to']) ? 'custom' : $defaultPeriod);
        $period = null;
        if ($preset) {
            $from = isset($v['from']) ? CarbonImmutable::parse($v['from']) : null;
            $to = isset($v['to']) ? CarbonImmutable::parse($v['to']) : null;
            if ($preset === 'custom') {
                // One end missing: open on that side (from the first day / up to today).
                $from ??= CarbonImmutable::create(2000, 1, 1);
                $to ??= CarbonImmutable::today();
            }
            $period = (new FilterResolver())->resolve(DatePreset::from($preset), $from, $to);
            $v['period'] = $preset;
        }

        return new self($v, $period);
    }

    public function get(string $key): ?string
    {
        return isset($this->values[$key]) ? (string) $this->values[$key] : null;
    }

    /** Any filter set beyond the page's default period (shows "Clear"). */
    public function any(?string $defaultPeriod = null): bool
    {
        return array_diff_key($this->values, ['period' => 1, 'from' => 1, 'to' => 1]) !== []
            || $this->get('period') !== $defaultPeriod;
    }

    /** How many filters are on, not counting search or the page's default period (the Filters button badge). */
    public function count(?string $defaultPeriod = null): int
    {
        return count(array_diff_key($this->values, ['search' => 1, 'from' => 1, 'to' => 1, 'period' => 1, 'amount_max' => 1]))
            + (! isset($this->values['amount_min']) && isset($this->values['amount_max']) ? 1 : 0)
            + ($this->get('period') !== $defaultPeriod ? 1 : 0);
    }

    /**
     * The active filters as removable chips: each links to the same page without that filter (page reset).
     *
     * @return list<array{label: string, url: string}>
     */
    public function chips(string $action, ?string $defaultPeriod = null): array
    {
        $keep = $this->values;
        if (($keep['period'] ?? null) === $defaultPeriod) {
            unset($keep['period']); // the default needs no parameter
        }
        $url = function (array $drop) use ($keep, $action) {
            $q = array_diff_key($keep, array_flip($drop));
            return $action . ($q ? '?' . http_build_query($q) : '');
        };
        $rupees = fn ($v) => '₹' . number_format((float) $v, fmod((float) $v, 1) ? 2 : 0); // same grouping as the rest of Dhiran

        $chips = [];
        if (isset($keep['period'])) {
            $chips[] = ['label' => $keep['period'] === 'custom' ? $this->period->label : self::PERIODS[$keep['period']], 'url' => $url(['period', 'from', 'to'])];
        }
        foreach (['status' => self::STATUSES, 'metal' => self::METALS, 'overdue' => self::OVERDUE, 'maturing' => self::MATURING, 'cash' => self::CASH_TYPES] as $key => $labels) {
            if (isset($keep[$key])) {
                $chips[] = ['label' => $labels[$keep[$key]], 'url' => $url([$key])];
            }
        }
        $min = $keep['amount_min'] ?? null;
        $max = $keep['amount_max'] ?? null;
        if ($min !== null || $max !== null) {
            $label = $min !== null && $max !== null ? $rupees($min) . ' – ' . $rupees($max) : ($min !== null ? 'From ' . $rupees($min) : 'Up to ' . $rupees($max));
            $chips[] = ['label' => $label, 'url' => $url(['amount_min', 'amount_max'])];
        }
        if (isset($keep['search'])) {
            $chips[] = ['label' => '“' . $keep['search'] . '”', 'url' => $url(['search'])];
        }

        return $chips;
    }

    public function from(): ?string
    {
        return $this->period?->from->toDateString();
    }

    public function to(): ?string
    {
        return $this->period?->to->toDateString();
    }

    public function applyPeriod(Builder $q, string $column): Builder
    {
        return $this->period
            ? $q->whereBetween($column, [$this->period->from->toDateTimeString(), $this->period->to->toDateTimeString()])
            : $q;
    }

    /** Loan number, or the customer's name or mobile. */
    public function applySearch(Builder $q): Builder
    {
        if (! ($s = $this->get('search'))) {
            return $q;
        }
        $like = '%' . addcslashes($s, '%_\\') . '%';

        return $q->where(fn ($w) => $w->where('loan_number', 'ilike', $like)
            ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('mobile', 'ilike', $like)
                ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$like])));
    }

    /** Everything that narrows a DhiranLoan query; $dateColumn is what the period means on this page. */
    public function applyToLoans(Builder $q, ?string $dateColumn = 'loan_date'): Builder
    {
        if ($dateColumn) {
            $this->applyPeriod($q, $dateColumn);
        }
        $this->applySearch($q);
        if ($s = $this->get('status')) {
            $q->where('status', $s);
        }
        if ($m = $this->get('metal')) {
            $q->whereHas('items', fn ($i) => $i->where('metal_type', $m));
        }
        if (($min = $this->get('amount_min')) !== null) {
            $q->where('principal_amount', '>=', $min);
        }
        if (($max = $this->get('amount_max')) !== null) {
            $q->where('principal_amount', '<=', $max);
        }
        $today = CarbonImmutable::today();
        if ($band = $this->get('overdue')) {
            // Days past the maturity date, on loans still open.
            [$lo, $hi] = ['1-30' => [1, 30], '31-90' => [31, 90], '90+' => [91, null]][$band];
            $q->where('status', 'active')->where('maturity_date', '<=', $today->subDays($lo)->toDateString());
            if ($hi !== null) {
                $q->where('maturity_date', '>=', $today->subDays($hi)->toDateString());
            }
        }
        if ($days = $this->get('maturing')) {
            $q->where('status', 'active')->whereBetween('maturity_date', [$today->toDateString(), $today->addDays((int) $days)->toDateString()]);
        }

        return $q;
    }
}
