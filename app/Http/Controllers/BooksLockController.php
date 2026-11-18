<?php

namespace App\Http\Controllers;

use App\Services\FinancialLockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/** Settings → Billing → Lock books. Owner only (route middleware role:owner + the service re-checks). */
class BooksLockController extends Controller
{
    public function lock(Request $request, FinancialLockService $service)
    {
        $data = $request->validate([
            'confirm' => 'accepted',
            'shortcut' => 'nullable|in:last_year',
            'through_date' => 'nullable|string|max:10',
            'reason' => 'nullable|string|max:500',
        ], ['confirm.accepted' => __('Tick the box to confirm you want to lock the books.')]);

        $user = Auth::user();
        $reason = trim((string) ($data['reason'] ?? ''));
        try {
            if (($data['shortcut'] ?? null) === 'last_year') {
                $service->lockThroughLastFinancialYear($user->shop, $user, $reason);
            } else {
                $service->lock($user->shop, (string) ($data['through_date'] ?? ''), $user, $reason);
            }
        } catch (InvalidArgumentException $e) {
            return $this->back()->withErrors(['through_date' => $e->getMessage()]);
        } catch (AuthorizationException) {
            abort(403);
        }

        return $this->back()->with('success', __('Books are locked.'));
    }

    public function unlock(Request $request, FinancialLockService $service)
    {
        $data = $request->validate([
            'confirm' => 'accepted',
            'reason' => 'required|string|max:500',
            'through_date' => 'nullable|string|max:10',
        ], ['confirm.accepted' => __('Tick the box to confirm you want to unlock the books.')]);

        $user = Auth::user();
        try {
            $service->unlock($user->shop, $data['through_date'] ?? null, $user, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return $this->back()->withErrors(['reason' => $e->getMessage()]);
        } catch (AuthorizationException) {
            abort(403);
        }

        return $this->back()->with('success', __('Books are unlocked.'));
    }

    private function back()
    {
        return redirect()->route('settings.edit', ['tab' => 'billing']);
    }
}
