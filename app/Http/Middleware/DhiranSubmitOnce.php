<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Dhiran money form takes effect once (owner 2026-11-12). Each form carries a fresh `idempotency_key`; the first
 * accepted submission claims it, in the same transaction as the money it moves. Sending that form again (double
 * click, a second tab, a browser retry) records nothing and leads to where the first one did; the same key with other
 * values is refused. A refused attempt (validation, a business rule, a period lock) releases its key, so the corrected
 * form goes through. Same table and scope as the mobile API's EnsureIdempotency; keys are namespaced "dhiran:".
 */
class DhiranSubmitOnce
{
    public const ALREADY = 'Already recorded: this form was sent before.';

    public function handle(Request $request, Closure $next): Response
    {
        $request->validate(
            ['idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/']],
            ['idempotency_key.*' => 'This form has expired. Reload the page and try again.'],
        );

        $scope = ['shop_id' => (int) $request->user()->shop_id, 'user_id' => (int) $request->user()->id, 'key' => 'dhiran:' . $request->input('idempotency_key')];
        $hash = hash('sha256', $request->path() . '|' . json_encode($request->except(['_token', 'idempotency_key'])));

        if ($seen = IdempotencyKey::where($scope)->first()) {
            return $this->replay($seen, $hash);
        }

        DB::beginTransaction();
        try {
            $claim = IdempotencyKey::create($scope + ['request_hash' => $hash, 'response_status' => 302]);
            $response = $next($request);
        } catch (UniqueConstraintViolationException $e) {
            DB::rollBack();
            // Another tab claimed this key a moment earlier; anything else is not ours to hide.
            return ($seen = IdempotencyKey::where($scope)->first()) ? $this->replay($seen, $hash) : throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Laravel turns validation errors, aborts and the controller's caught business errors into responses before
        // they reach here, so "accepted" is read off the response: a redirect that flashed no error.
        $flashed = (array) $request->session()->get('_flash.new', []);
        if (! $response->isRedirection() || array_intersect(['error', 'errors'], $flashed)) {
            DB::rollBack();

            return $response;
        }

        $claim->update(['response_body' => ['to' => $response->headers->get('Location')]]);
        DB::commit();

        return $response;
    }

    private function replay(IdempotencyKey $seen, string $hash): Response
    {
        if (! hash_equals($seen->request_hash, $hash)) {
            return back()->with('error', 'This form was already sent with different values. Reload the page and try again.');
        }

        return redirect()->to($seen->response_body['to'] ?? url('/dhiran'))->with('success', self::ALREADY);
    }
}
