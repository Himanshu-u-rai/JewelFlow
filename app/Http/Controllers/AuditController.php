<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;

class AuditController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;

        $logs = AuditLog::where('shop_id', $shopId)
            ->orderBy('created_at', 'desc')
            ->paginate(50);
        
        // Stats (calculated separately from pagination)
        $stats = [
            'total' => AuditLog::where('shop_id', $shopId)->count(),
            'today' => AuditLog::where('shop_id', $shopId)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
        ];

        return view('report_audit', compact('logs', 'stats'));
    }
}
