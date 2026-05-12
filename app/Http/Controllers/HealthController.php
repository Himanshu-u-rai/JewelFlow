<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        $db = false;
        $queue = false;

        try {
            DB::select('SELECT 1');
            $db = true;
        } catch (\Throwable) {
        }

        try {
            DB::table('jobs')->count();
            $queue = true;
        } catch (\Throwable) {
        }

        $status = ($db && $queue) ? 'ok' : 'degraded';
        $code   = $status === 'ok' ? 200 : 503;

        return response()->json([
            'status'    => $status,
            'db'        => $db,
            'queue'     => $queue,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }

    public function ping(): JsonResponse
    {
        return response()->json(['status' => 'pong']);
    }
}
