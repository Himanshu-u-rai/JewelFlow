{{-- A fresh key per rendered form: sending the same form twice takes effect once (App\Http\Middleware\DhiranSubmitOnce). --}}
<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
