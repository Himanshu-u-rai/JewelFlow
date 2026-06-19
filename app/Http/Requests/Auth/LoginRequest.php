<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Realm;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'digits:10'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'mobile_number.digits' => 'Mobile number must be exactly 10 digits.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Login is scoped to the current realm (from the host): a login on
        // dhiran.* only ever authenticates a Dhiran account, and the main domain
        // only an ERP account. The same mobile may exist in both realms, so the
        // realm is part of both the existence check and the auth attempt.
        $realm = Realm::current($this);

        // Product decision: distinguish "unknown mobile" from "wrong password"
        // so users know whether to register. Enumeration risk is capped by the
        // IP-level secondary throttle in ensureIsNotRateLimited().
        $userExists = User::where('mobile_number', $this->input('mobile_number'))
            ->where('realm', $realm)
            ->exists();

        if (! $userExists) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey());
            session()->flash('login_modal', 'not_registered');

            throw ValidationException::withMessages([
                'mobile_number' => 'This mobile number is not registered. Please register to continue.',
            ]);
        }

        if (! Auth::attempt(
            array_merge($this->only('mobile_number', 'password'), ['realm' => $realm]),
            $this->boolean('remember'),
        )) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey());
            session()->flash('login_modal', 'wrong_password');

            throw ValidationException::withMessages([
                'password' => 'Incorrect password. Please try again.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * Two limits apply:
     *  - Per (mobile|ip): 5/min — protects a single account from brute-force.
     *  - Per IP: 30/min  — caps enumeration across different mobile numbers
     *    from a single IP, since unknown-mobile now returns a distinct error.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $mobileLimited = RateLimiter::tooManyAttempts($this->throttleKey(), 5);
        $ipLimited     = RateLimiter::tooManyAttempts($this->ipThrottleKey(), 30);

        if (! $mobileLimited && ! $ipLimited) {
            return;
        }

        event(new Lockout($this));

        $seconds = $mobileLimited
            ? RateLimiter::availableIn($this->throttleKey())
            : RateLimiter::availableIn($this->ipThrottleKey());

        session()->flash('login_modal', 'throttled');
        session()->flash('login_modal_seconds', $seconds);

        throw ValidationException::withMessages([
            'mobile_number' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Per-(mobile|ip) throttle key.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('mobile_number')).'|'.$this->ip());
    }

    /**
     * Per-IP throttle key for enumeration cap.
     */
    public function ipThrottleKey(): string
    {
        return 'login-ip:'.$this->ip();
    }
}
