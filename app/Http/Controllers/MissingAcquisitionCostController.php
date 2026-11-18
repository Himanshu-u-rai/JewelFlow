<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\AcquisitionCostService;
use App\Services\StockAsAt;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Items without a purchase cost" — fill in the real cost of pieces whose cost was never recorded, so year-end stock
 * can be valued. Routes require inventory.edit AND reports.money (cost is confidential). Each cost is set once.
 */
class MissingAcquisitionCostController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Item::query()
            ->whereNull('acquisition_cost')
            ->whereIn('status', StockAsAt::STOCK_STATUSES)
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)) . '%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(barcode) LIKE ?', [$like])->orWhereRaw('LOWER(design) LIKE ?', [$like]));
            })
            ->orderBy('id');

        $items = $query->paginate(self::PER_PAGE)->withQueryString();
        $ids = $items->pluck('id')->all();

        // Latest purchase line that references each piece: its purchase rate is the best hint we have.
        $rates = DB::table('stock_purchase_items')->whereIn('item_id', $ids ?: [0])
            ->where('shop_id', Auth::user()->shop_id)
            ->orderBy('id')->pluck('purchase_rate_per_gram', 'item_id');

        return view('inventory.missing-costs', [
            'items' => $items,
            'search' => $search,
            'purchaseRates' => $rates,
            'total' => Item::query()->whereNull('acquisition_cost')->whereIn('status', StockAsAt::STOCK_STATUSES)->count(),
        ]);
    }

    public function store(Request $request, AcquisitionCostService $service)
    {
        $data = $request->validate([
            'costs' => ['required', 'array', 'max:200'],
            'costs.*' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
            'only' => ['nullable', 'integer'],
        ]);

        $costs = collect($data['costs'])->filter(fn ($v) => trim((string) $v) !== '');
        if (! empty($data['only'])) {
            $costs = $costs->only([(string) $data['only']]);
        }

        $user = Auth::user();
        $saved = 0;
        $errors = [];
        foreach ($costs as $id => $amount) {
            if (! ctype_digit((string) $id)) {
                continue;
            }
            // Tenant-scoped lookup: another shop's piece is simply "not found".
            $item = Item::query()->find((int) $id);
            try {
                if (! $item) {
                    throw new DomainException(__('This piece was not found.'));
                }
                $service->fill($item, $amount, $user, $data['note'] ?? null);
                $saved++;
            } catch (DomainException $e) {
                $errors["costs.{$id}"] = $e->getMessage();
            }
        }

        $back = redirect()->route('inventory.missing-costs', array_filter(['search' => $request->input('search'), 'page' => $request->input('page')]))
            ->with('missing_cost_saved', $saved);
        if ($saved > 0) {
            $back->with('success', trans_choice(':count purchase cost saved.|:count purchase costs saved.', $saved, ['count' => $saved]));
        }

        return $errors ? $back->withErrors($errors)->withInput() : $back;
    }
}
