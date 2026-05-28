<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\StoneComponent;
use App\Models\StoneType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Phase 2A — Operator endpoint for managing stones on inventory items.
 *
 * Rules enforced:
 *   - Only in-stock items accept new stones (sold/returned items have
 *     their stones snapshotted on the invoice / return — those are
 *     immutable via the snapshot guard trigger).
 *   - stone_type must exist in stone_types for the shop, or be a
 *     free-text 'other'.
 *   - total_value = round(unit_value * count, 2) — DB CHECK enforces
 *     this; controller pre-validates for a clear UX error.
 *   - Removal/edit blocked once stone is snapshotted on a sale or
 *     return — enforced by the StoneComponent model's bespoke updating
 *     observer AND the DB trigger.
 *
 * Constitutional alignment:
 *   - Article XIV (Manual Valuation Boundary): every write here is
 *     operator-initiated, audit-logged, and never touched by automation.
 *   - Article I (Inviolability): once linked to a finalized parent,
 *     the row is immutable except `notes`.
 */
class ItemStoneController extends Controller
{
    /**
     * List stone components on the given inventory item.
     */
    public function index(Item $item, Request $request)
    {
        $this->assertShopOwnership($item, $request);

        $stones = StoneComponent::query()
            ->where('item_id', $item->id)
            ->whereNull('invoice_item_id')
            ->whereNull('return_line_item_id')
            ->orderBy('id')
            ->get();

        $availableTypes = StoneType::query()
            ->where('shop_id', $item->shop_id)
            ->whereRaw('is_active IS TRUE')
            ->orderBy('sort_order')
            ->get(['id', 'code', 'label']);

        return response()->json([
            'item_id'         => (int) $item->id,
            'stones'          => $stones,
            'available_types' => $availableTypes,
            'item_status'     => $item->status,
            'editable'        => $item->status === 'in_stock',
        ]);
    }

    /**
     * Add a stone to an in-stock item.
     */
    public function store(Item $item, Request $request)
    {
        $this->assertShopOwnership($item, $request);
        $this->assertItemEditable($item);

        $validated = $this->validateStone($item->shop_id, $request);

        $stone = DB::transaction(function () use ($item, $validated, $request) {
            $stone = StoneComponent::record(array_merge($validated, [
                'shop_id'              => (int) $item->shop_id,
                'item_id'              => (int) $item->id,
                'invoice_item_id'      => null,
                'return_line_item_id'  => null,
                'migrated_from_legacy' => false,
            ]));

            \App\Models\AuditLog::create([
                'shop_id'     => (int) $item->shop_id,
                'user_id'     => $request->user()->id,
                'action'      => 'stone_added_to_item',
                'model_type'  => 'stone_component',
                'model_id'    => (int) $stone->id,
                'description' => "Stone added to item #{$item->id}: {$stone->stone_type} × {$stone->count} @ ₹{$stone->unit_value}",
                'data'        => $validated,
            ]);

            return $stone;
        });

        return response()->json(['stone' => $stone], 201);
    }

    /**
     * Update a stone on an in-stock item. The snapshot guard trigger
     * AND the Eloquent observer on StoneComponent enforce that snapshotted
     * stones can have only `notes` edited — so the trigger is the final
     * authority. We still pre-validate at the controller for clearer UX.
     */
    public function update(Item $item, StoneComponent $stone, Request $request)
    {
        $this->assertShopOwnership($item, $request);
        abort_unless($stone->item_id === $item->id, 404);

        if ($stone->invoice_item_id !== null || $stone->return_line_item_id !== null) {
            // Snapshotted: only notes is mutable.
            $validated = $request->validate(['notes' => 'nullable|string|max:1000']);
            $stone->update($validated); // observer will block anything else
        } else {
            $this->assertItemEditable($item);
            $validated = $this->validateStone($item->shop_id, $request);
            $stone->update($validated);
        }

        \App\Models\AuditLog::create([
            'shop_id'     => (int) $item->shop_id,
            'user_id'     => $request->user()->id,
            'action'      => 'stone_updated',
            'model_type'  => 'stone_component',
            'model_id'    => (int) $stone->id,
            'description' => "Stone #{$stone->id} on item #{$item->id} updated.",
            'data'        => $validated,
        ]);

        return response()->json(['stone' => $stone->fresh()]);
    }

    /**
     * Delete a stone from an in-stock item. The snapshot guard trigger
     * blocks deletion of snapshotted stones — the controller catches
     * that and returns a clearer 422.
     */
    public function destroy(Item $item, StoneComponent $stone, Request $request)
    {
        $this->assertShopOwnership($item, $request);
        abort_unless($stone->item_id === $item->id, 404);

        if ($stone->invoice_item_id !== null || $stone->return_line_item_id !== null) {
            return response()->json([
                'message' => 'This stone is snapshotted on a finalized invoice or settled return. It cannot be deleted.',
            ], 422);
        }

        $this->assertItemEditable($item);

        DB::transaction(function () use ($stone, $item, $request) {
            \App\Models\AuditLog::create([
                'shop_id'     => (int) $item->shop_id,
                'user_id'     => $request->user()->id,
                'action'      => 'stone_removed_from_item',
                'model_type'  => 'stone_component',
                'model_id'    => (int) $stone->id,
                'description' => "Stone #{$stone->id} removed from item #{$item->id}.",
                'data'        => $stone->only(['stone_type', 'carat_weight', 'count', 'unit_value', 'total_value']),
            ]);

            $stone->delete();
        });

        return response()->json(['ok' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function assertShopOwnership(Item $item, Request $request): void
    {
        abort_unless((int) $item->shop_id === (int) $request->user()->shop_id, 403);
    }

    private function assertItemEditable(Item $item): void
    {
        if ($item->status !== 'in_stock') {
            throw new LogicException(
                "Stones can only be edited on in_stock items. Item is currently '{$item->status}'."
            );
        }
    }

    private function validateStone(int $shopId, Request $request): array
    {
        $allowedTypes = StoneType::query()
            ->where('shop_id', $shopId)
            ->whereRaw('is_active IS TRUE')
            ->pluck('code')
            ->all();

        // Always permit 'other' as a fallback even if not explicitly seeded.
        if (! in_array('other', $allowedTypes, true)) {
            $allowedTypes[] = 'other';
        }

        $validated = $request->validate([
            'stone_type'   => ['required', 'string', Rule::in($allowedTypes)],
            'carat_weight' => 'nullable|numeric|min:0|max:99999.999',
            'count'        => 'required|integer|min:1|max:9999',
            'unit_value'   => 'required|numeric|min:0|max:99999999.99',
            'notes'        => 'nullable|string|max:1000',
            // Phase 2B fields — all nullable, all descriptive metadata.
            'certificate_id'        => 'nullable|string|max:60',
            'certificate_authority' => 'nullable|string|max:40',
            'grade'                 => 'nullable|string|max:40',
            'supplier_name'         => 'nullable|string|max:120',
            'photo_path'            => 'nullable|string|max:255',
        ]);

        // Pre-compute total_value to match the DB CHECK constraint:
        //   ROUND(unit_value * count, 2) == ROUND(total_value, 2)
        $validated['total_value'] = round(
            ((float) $validated['unit_value']) * ((int) $validated['count']),
            2
        );

        return $validated;
    }

    /**
     * Phase 2B — Revalue a stone in inventory.
     *
     * POST /inventory/items/{item}/stones/{stone}/revalue
     *   { "new_unit_value": ..., "new_count": ..., "reason": "..." }
     *
     * Delegates to StoneRevaluationService which:
     *   - Refuses snapshotted stones
     *   - Refuses non-in-stock items
     *   - Writes an append-only stone_revaluation_events row
     *   - Updates stone_components.unit_value + count + total_value
     *     atomically
     *   - Writes an AuditLog entry
     */
    public function revalue(Item $item, StoneComponent $stone, Request $request, \App\Services\StoneRevaluationService $service)
    {
        $this->assertShopOwnership($item, $request);
        abort_unless($stone->item_id === $item->id, 404);

        $validated = $request->validate([
            'new_unit_value' => 'required|numeric|min:0|max:99999999.99',
            'new_count'      => 'required|integer|min:1|max:9999',
            'reason'         => 'required|string|min:5|max:1000',
        ]);

        try {
            $event = $service->revalue(
                $stone,
                (float) $validated['new_unit_value'],
                (int) $validated['new_count'],
                (string) $validated['reason'],
                (int) $request->user()->id
            );
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'event' => $event,
            'stone' => $stone->fresh(),
        ], 201);
    }

    /**
     * Phase 2B — List revaluation history for a stone.
     *
     * GET /inventory/items/{item}/stones/{stone}/revaluations
     */
    public function revaluations(Item $item, StoneComponent $stone, Request $request)
    {
        $this->assertShopOwnership($item, $request);
        abort_unless($stone->item_id === $item->id, 404);

        return response()->json([
            'stone_id'    => (int) $stone->id,
            'revaluations'=> $stone->revaluationEvents()->get(),
        ]);
    }
}
