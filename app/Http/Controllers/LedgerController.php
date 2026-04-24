<?php

namespace App\Http\Controllers;

use App\Models\MetalMovement;

class LedgerController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;

        $movements = \App\Models\MetalMovement::where('shop_id', $shopId)
            ->with([
                'fromLot',
                'toLot',
                'item',
                'invoice',
                'user'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return view('ledger', compact('movements'));
    }
}
