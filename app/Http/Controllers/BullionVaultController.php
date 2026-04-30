<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Vendor;
use App\Services\BullionVaultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BullionVaultController extends Controller
{
    public function __construct(private BullionVaultService $vault) {}

    public function index()
    {
        $shopId = auth()->user()->shop_id;

        $balances = $this->vault->vaultBalances($shopId);
        $recentMovements = $this->vault->recentLedger($shopId, 25);
        $openJobs = JobOrder::query()
            ->whereIn('status', [
                JobOrder::STATUS_ISSUED,
                JobOrder::STATUS_PARTIAL_RETURN,
            ])
            ->with('karigar')
            ->latest()
            ->limit(10)
            ->get();
        $lots = MetalLot::query()
            ->where('shop_id', $shopId)
            ->with('vendor:id,name')
            ->orderByDesc('id')
            ->get();

        return view('vault.index', compact('balances', 'recentMovements', 'openJobs', 'lots'));
    }

    public function ledger(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = MetalMovement::query()->where('shop_id', $shopId)->latest('id');

        $type = $request->input('type');
        if ($type) {
            $query->where('type', $type);
        }

        $from = $request->input('from');
        $to = $request->input('to');
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $movements = $query->paginate(50)->withQueryString();

        $types = MetalMovement::query()
            ->where('shop_id', $shopId)
            ->select('type')
            ->distinct()
            ->pluck('type');

        return view('vault.ledger', compact('movements', 'types', 'type', 'from', 'to'));
    }

    public function showLot(MetalLot $metalLot)
    {
        abort_unless($metalLot->shop_id === auth()->user()->shop_id, 403);

        // All movements where this lot was debited (issued out) or credited (received in)
        $movements = MetalMovement::query()
            ->where('shop_id', auth()->user()->shop_id)
            ->where(function ($q) use ($metalLot) {
                $q->where('from_lot_id', $metalLot->id)
                  ->orWhere('to_lot_id', $metalLot->id);
            })
            ->orderByDesc('id')
            ->get();

        // Resolve job order numbers for job_issue / job_return movements
        $jobOrderIds = $movements
            ->where('reference_type', 'job_order')
            ->pluck('reference_id')
            ->unique();
        $jobOrders = JobOrder::query()
            ->whereIn('id', $jobOrderIds)
            ->get(['id', 'job_order_number', 'karigar_id'])
            ->keyBy('id');

        $metalLot->load('vendor:id,name');

        return view('vault.show-lot', compact('metalLot', 'movements', 'jobOrders'));
    }

    public function createLot()
    {
        $vendors = Vendor::active()->orderBy('name')->get(['id', 'name']);
        return view('vault.create-lot', compact('vendors'));
    }

    public function storeLot(Request $request)
    {
        $validated = $request->validate([
            'metal_type' => 'required|in:gold,silver',
            'source' => 'required|in:purchase,buyback,opening',
            'gross_weight' => 'required|numeric|min:0.001',
            'purity' => 'required|numeric|min:1|max:1000',
            'cost_per_gram' => 'nullable|numeric|min:0',
            'vendor_id' => ['nullable', \Illuminate\Validation\Rule::exists('vendors', 'id')->where('shop_id', $shopId)],
            'notes' => 'nullable|string',
        ]);

        $shopId = auth()->user()->shop_id;
        $userId = (int) auth()->id();

        // Gold purity is in Karats (24 = pure), silver is fineness (999 = pure)
        $gross = (float) $validated['gross_weight'];
        $purity = (float) $validated['purity'];
        $fineWeight = $validated['metal_type'] === 'silver'
            ? round($gross * ($purity / 1000), 6)
            : round($gross * ($purity / 24), 6);
        $totalCost = round(((float) ($validated['cost_per_gram'] ?? 0)) * $gross, 2);
        $costPerFineGram = $fineWeight > 0 ? round($totalCost / $fineWeight, 2) : 0;

        $lot = DB::transaction(function () use ($validated, $shopId, $userId, $fineWeight, $totalCost, $costPerFineGram) {
            $lot = MetalLot::create([
                'shop_id' => $shopId,
                'vendor_id' => $validated['vendor_id'] ?? null,
                'metal_type' => $validated['metal_type'],
                'source' => $validated['source'],
                'purity' => $validated['purity'],
                'fine_weight_total' => $fineWeight,
                'fine_weight_remaining' => $fineWeight,
                'cost_per_fine_gram' => $costPerFineGram,
                'notes' => $validated['notes'] ?? null,
            ]);

            MetalMovement::record([
                'shop_id' => $shopId,
                'from_lot_id' => null,
                'to_lot_id' => $lot->id,
                'fine_weight' => $fineWeight,
                'type' => $validated['source'],
                'reference_type' => 'metal_lot',
                'reference_id' => $lot->id,
                'user_id' => $userId,
            ]);

            if ($totalCost > 0) {
                CashTransaction::record([
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'type' => 'out',
                    'amount' => $totalCost,
                    'source_type' => 'bullion_' . $validated['source'],
                    'source_id' => $lot->id,
                    'description' => ucfirst($validated['metal_type']) . ' ' . $validated['source']
                        . " - Lot #{$lot->lot_number} - "
                        . number_format((float) $validated['gross_weight'], 3) . 'g @ '
                        . $validated['purity'] . ($validated['metal_type'] === 'gold' ? 'K' : '‰'),
                ]);
            }

            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => $userId,
                'action' => 'bullion_added',
                'model_type' => 'metal_lot',
                'model_id' => $lot->id,
                'data' => [
                    'metal_type' => $validated['metal_type'],
                    'source' => $validated['source'],
                    'gross_weight' => $validated['gross_weight'],
                    'purity' => $validated['purity'],
                    'fine_weight' => $fineWeight,
                    'cost' => $totalCost,
                    'vendor_id' => $validated['vendor_id'] ?? null,
                ],
            ]);

            return $lot;
        });

        return redirect()->route('vault.index')
            ->with('success', 'Bullion added to vault. Lot #' . $lot->lot_number . ' — ' . number_format($fineWeight, 3) . 'g fine.');
    }
}
