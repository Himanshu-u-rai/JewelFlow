<x-guest-layout>
    <h2 class="text-2xl font-bold text-center text-gray-900 mb-6">Login to Your Shop</h2>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @php
        $modalType = session('login_modal');
        $throttleSeconds = (int) session('login_modal_seconds', 0);
        $modalMessage = match ($modalType) {
            'not_registered'         => $errors->first('mobile_number') ?: 'This mobile number is not registered. Please register to continue.',
            'wrong_password'         => $errors->first('password') ?: 'Incorrect password. Please try again.',
            'account_inactive'       => $errors->first('mobile_number') ?: 'Your account is inactive. Please contact support.',
            'shop_deactivated'       => $errors->first('mobile_number') ?: 'Your shop is currently deactivated. Please contact support.',
            'throttled'              => $errors->first('mobile_number') ?: "Too many attempts. Please try again in {$throttleSeconds} seconds.",
            'already_on_mobile'      => 'This account is already logged in on the mobile app. Log out of the mobile app first, then try again here.',
            'web_session_limit_reached' => $errors->first('mobile_number') ?: 'All web seats for this shop are in use. Ask an active user to log out first.',
            default                  => null,
        };
        $modalFocusTarget = match ($modalType) {
            'wrong_password' => 'password',
            default          => 'mobile_number',
        };
        $modalAccent = match ($modalType) {
            'not_registered'            => ['bg' => 'bg-amber-100', 'text' => 'text-amber-600', 'title' => 'Mobile Number Not Registered'],
            'wrong_password'            => ['bg' => 'bg-red-100',   'text' => 'text-red-600',   'title' => 'Incorrect Password'],
            'account_inactive'          => ['bg' => 'bg-red-100',   'text' => 'text-red-600',   'title' => 'Account Inactive'],
            'shop_deactivated'          => ['bg' => 'bg-red-100',   'text' => 'text-red-600',   'title' => 'Shop Deactivated'],
            'throttled'                 => ['bg' => 'bg-slate-100', 'text' => 'text-slate-600', 'title' => 'Too Many Attempts'],
            'already_on_mobile'         => ['bg' => 'bg-orange-100','text' => 'text-orange-600','title' => 'Already Logged In on Mobile'],
            'web_session_limit_reached' => ['bg' => 'bg-red-100',   'text' => 'text-red-600',   'title' => 'All Web Seats In Use'],
            default                     => null,
        };
    @endphp

    @if ($modalType && $modalAccent)
        <div id="login-alert-modal" role="dialog" aria-modal="true"
            aria-labelledby="login-alert-title" aria-describedby="login-alert-desc"
            data-focus-target="{{ $modalFocusTarget }}"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
            <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full {{ $modalAccent['bg'] }} flex items-center justify-center">
                        @if ($modalType === 'not_registered')
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 {{ $modalAccent['text'] }}">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                            </svg>
                        @else
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 {{ $modalAccent['text'] }}">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                        @endif
                    </div>
                    <div class="flex-1">
                        <h3 id="login-alert-title" class="text-lg font-semibold text-gray-900">{{ $modalAccent['title'] }}</h3>
                        <p id="login-alert-desc" class="mt-1 text-sm text-gray-600">{{ $modalMessage }}</p>
                    </div>
                </div>
                <div class="mt-5 flex gap-2 justify-end">
                    @if ($modalType === 'not_registered')
                        <button type="button" data-login-alert-close
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500">
                            Try Again
                        </button>
                        <a href="{{ route('register') }}"
                            class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                            Register Now
                        </a>
                    @elseif ($modalType === 'wrong_password')
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500">
                                Forgot Password
                            </a>
                        @endif
                        <button type="button" data-login-alert-close id="login-alert-primary"
                            class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                            Try Again
                        </button>
                    @else
                        <button type="button" data-login-alert-close id="login-alert-primary"
                            class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                            OK
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <script>
            (function () {
                var modal = document.getElementById('login-alert-modal');
                if (!modal) return;
                var dialog = modal.firstElementChild;
                var focusTargetName = modal.getAttribute('data-focus-target') || 'mobile_number';

                function focusables() {
                    return modal.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
                }

                function trap(e) {
                    if (e.key !== 'Tab') return;
                    var list = focusables();
                    if (!list.length) return;
                    var first = list[0];
                    var last = list[list.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }

                function dismiss() {
                    modal.remove();
                    document.removeEventListener('keydown', keyHandler);
                    var target = document.getElementsByName(focusTargetName)[0]
                              || document.getElementById(focusTargetName);
                    if (target) target.focus();
                }

                function keyHandler(e) {
                    if (e.key === 'Escape') { dismiss(); return; }
                    trap(e);
                }

                modal.querySelectorAll('[data-login-alert-close]').forEach(function (el) {
                    el.addEventListener('click', dismiss);
                });
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) dismiss();
                });
                document.addEventListener('keydown', keyHandler);

                var initial = modal.querySelector('#login-alert-primary')
                           || modal.querySelector('[data-login-alert-close]')
                           || modal.querySelector('a, button');
                if (initial) initial.focus();
            })();
        </script>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="mobile_number" :value="__('Mobile Number')" />
            <x-text-input id="mobile_number"
                class="block mt-1 w-full"
                type="tel"
                name="mobile_number"
                :value="old('mobile_number')"
                required
                autofocus
                autocomplete="tel"
                pattern="[0-9]{10}"
                minlength="10"
                maxlength="10"
                placeholder="Enter 10-digit mobile number" />
            @unless ($modalType)
                <x-input-error :messages="$errors->get('mobile_number')" class="mt-2" />
            @endunless
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <div class="relative mt-1">
                <x-text-input id="password"
                    class="block w-full pr-10"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password" />
                <button type="button"
                    id="password-peek"
                    aria-label="Hold to show password"
                    aria-pressed="false"
                    tabindex="-1"
                    class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-700 focus:outline-none focus:text-amber-600 select-none touch-none">
                    <svg id="password-peek-eye" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg id="password-peek-eye-off" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-5 h-5 hidden">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                </button>
            </div>
            @unless ($modalType)
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            @endunless
        </div>

        <script>
            (function () {
                var input = document.getElementById('password');
                var btn = document.getElementById('password-peek');
                var eye = document.getElementById('password-peek-eye');
                var eyeOff = document.getElementById('password-peek-eye-off');
                if (!input || !btn) return;

                function show() {
                    input.type = 'text';
                    eye.classList.add('hidden');
                    eyeOff.classList.remove('hidden');
                    btn.setAttribute('aria-pressed', 'true');
                }
                function hide() {
                    input.type = 'password';
                    eyeOff.classList.add('hidden');
                    eye.classList.remove('hidden');
                    btn.setAttribute('aria-pressed', 'false');
                }

                btn.addEventListener('pointerdown', function (e) { e.preventDefault(); show(); });
                btn.addEventListener('pointerup', hide);
                btn.addEventListener('pointerleave', hide);
                btn.addEventListener('pointercancel', hide);
                btn.addEventListener('blur', hide);
                window.addEventListener('blur', hide);
            })();
        </script>

        <div class="flex items-center">
            <input id="remember_me" type="checkbox"
                class="rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500"
                name="remember"
                @checked(old('remember'))>
            <label for="remember_me" class="ml-2 text-sm text-gray-600">
                {{ __('Remember me') }}
            </label>
        </div>

        <div class="flex items-center justify-between gap-3 mt-6">
            @if (Route::has('password.request'))
                <a class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition"
                    href="{{ route('password.request') }}">
                    {{ __('Forgot Password') }}
                </a>
            @else
                <span></span>
            @endif

            <x-primary-button>
                {{ __('Log in') }}
            </x-primary-button>
        </div>

        <div class="text-center pt-2">
            <a class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition"
                href="{{ route('register') }}">
                {{ __('New User? Register') }}
            </a>
        </div>
    </form>
</x-guest-layout>
