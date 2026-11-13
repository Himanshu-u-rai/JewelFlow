<?php

namespace App\Http\Middleware;

use App\Models\OnboardingBatch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every change to an onboarding batch runs in one transaction that first re-reads the batch under a row lock and
 * refuses unless it is still editable (audit N14, 2026-11-13). The action then works on that locked copy.
 *
 * Without this, each action checked "editable" on the copy loaded with the page: a cancel waiting on a posting's
 * lock turned "locked" into "cancelled" after the opening rows were posted, and entries could be added to a posted
 * batch. Locking (posting) has its own row lock and is not wrapped here.
 */
class LockEditableOnboardingBatch
{
    public function handle(Request $request, Closure $next): Response
    {
        $batch = $request->route('onboarding');
        if (! $batch instanceof OnboardingBatch || $request->isMethodSafe()) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next, $batch) {
            $locked = OnboardingBatch::withoutTenant()->whereKey($batch->getKey())->lockForUpdate()->first();
            abort_unless($locked && $locked->isEditable(), 422, 'This batch is locked.');
            $request->route()->setParameter('onboarding', $locked);

            $response = $next($request);
            // The route pipeline renders an action's exception into a response before it reaches here; rethrow it
            // so the transaction rolls the half-done change back (it is rendered again further out).
            if (($response->exception ?? null) instanceof \Throwable) {
                throw $response->exception;
            }

            return $response;
        });
    }
}
