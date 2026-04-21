<?php

namespace App\Http\Controllers;

use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\CashTransaction;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoldInventoryController extends Controller
{
    /**
     * Display gold inventory (metal lots)
     */
    public function index()
    {
        $shopId = auth()->user()->shop_id;

        $lots = MetalLot::where('shop_id', $shopId)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalFineGold = $lots->sum('fine_weight_remaining');

        return view('inventory.gold.index', compact('lots', 'totalFineGold'));
    }

    /**
     * Show form to add gold (purchase/buyback)
     */
    public function create()
    {
        return view('inventory.gold.create');
    }

    /**
     * Store new gold lot
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'source' => 'required|in:purchase,buyback,opening',
            'gross_weight' => 'required|numeric|min:0.001',
            'purity' => 'required|numeric|min:1|max:24',
            'cost_per_gram' => 'nullable|numeric|min:0',
            'supplier_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $shopId = auth()->user()->shop_id;

        // Calculate fine gold
        $fineGold = $validated['gross_weight'] * ($validated['purity'] / 24);
        
        // Calculate total cost
        $totalCost = ($validated['cost_per_gram'] ?? 0) * $validated['gross_weight'];
        $costPerFineGram = $fineGold > 0 ? $totalCost / $fineGold : 0;

        DB::transaction(function () use ($validated, $shopId, $fineGold, $totalCost, $costPerFineGram) {
            // Create the metal lot
            $lot = MetalLot::create([
                'shop_id' => $shopId,
                'source' => $validated['source'],
                'purity' => $validated['purity'],
                'fine_weight_total' => $fineGold,
                'fine_weight_remaining' => $fineGold,
                'cost_per_fine_gram' => $costPerFineGram,
            ]);

            // Record metal movement
            MetalMovement::record([
                'shop_id' => $shopId,
                'from_lot_id' => null,
                'to_lot_id' => $lot->id,
                'fine_weight' => $fineGold,
                'type' => $validated['source'],
                'reference_type' => 'metal_lot',
                'reference_id' => $lot->id,
                'user_id' => auth()->id(),
            ]);

            // Record cash transaction if there was a cost
            if ($totalCost > 0) {
                CashTransaction::record([
                    'shop_id' => $shopId,
                    'user_id' => auth()->id(),
                    'type' => 'out',
                    'amount' => $totalCost,
                    'source_type' => 'gold_' . $validated['source'],
                    'source_id' => $lot->id,
                    'description' => ucfirst($validated['source']) . " - Lot #{$lot->lot_number} - " . 
                        number_format($validated['gross_weight'], 3) . "g @ " . 
                        $validated['purity'] . "K",
                ]);
            }

            // Audit log
            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => auth()->id(),
                'action' => 'gold_' . $validated['source'],
                'model_type' => 'metal_lot',
                'model_id' => $lot->id,
                'data' => [
                    'gross_weight' => $validated['gross_weight'],
                    'purity' => $validated['purity'],
                    'fine_gold' => $fineGold,
                    'cost' => $totalCost,
                    'supplier' => $validated['supplier_name'] ?? null,
                ],
            ]);
        });

        return redirect()->route('inventory.gold.index')
            ->with('success', 'Gold added to inventory successfully! Fine gold: ' . number_format($fineGold, 3) . 'g');
    }

    /**
     * Show a specific lot
     */
    public function show(MetalLot $lot)
    {
        abort_if($lot->shop_id !== auth()->user()->shop_id, 403);

        $movements = MetalMovement::where('shop_id', auth()->user()->shop_id)
            ->where(function ($q) use ($lot) {
                $q->where('from_lot_id', $lot->id)
                  ->orWhere('to_lot_id', $lot->id);
            })
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('inventory.gold.show', compact('lot', 'movements'));
    }

    /**
     * Show form to edit a lot (only source and notes)
     */
    public function edit(MetalLot $lot)
    {
        abort_if($lot->shop_id !== auth()->user()->shop_id, 403);

        return view('inventory.gold.edit', compact('lot'));
    }

    /**
     * Update a lot (limited fields - can't change weight/purity)
     */
    public function update(Request $request, MetalLot $lot)
    {
        abort_if($lot->shop_id !== auth()->user()->shop_id, 403);

        $validated = $request->validate([
            'source' => 'required|in:purchase,buyback,opening',
            'cost_per_fine_gram' => 'nullable|numeric|min:0',
        ]);

        $lot->update($validated);

        AuditLog::create([
            'shop_id' => $lot->shop_id,
            'user_id' => auth()->id(),
            'action' => 'gold_lot_updated',
            'model_type' => 'metal_lot',
            'model_id' => $lot->id,
            'data' => $validated,
        ]);

        return redirect()->route('inventory.gold.show', $lot)
            ->with('success', 'Gold lot updated successfully.');
    }
}
