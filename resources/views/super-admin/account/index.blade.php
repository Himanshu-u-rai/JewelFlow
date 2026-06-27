<x-super-admin.layout>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-100">Account security</h1>
        <p class="text-sm text-slate-400 mt-1">Change your sign-in email and mobile number. Both require your password and an emailed code.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-4 py-2">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-4 py-2">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        {{-- EMAIL --}}
        <div class="admin-panel p-5">
            <h2 class="text-lg font-medium text-slate-100">Email</h2>
            <p class="mt-1 text-sm">
                <span class="text-slate-400">Current:</span>
                <span class="text-slate-100">{{ $admin->email ?? '— none —' }}</span>
                @if ($admin->email_verified_at)
                    <span class="ml-2 rounded bg-emerald-700/50 text-emerald-200 px-2 py-0.5 text-xs">verified</span>
                @else
                    <span class="ml-2 rounded bg-amber-700/50 text-amber-200 px-2 py-0.5 text-xs">unverified</span>
                @endif
            </p>

            @if ($admin->pending_email)
                <p class="mt-4 text-sm text-amber-200">Pending change to <strong>{{ $admin->pending_email }}</strong>. Enter the code we emailed there.</p>
                <form method="POST" action="{{ route('admin.account.email.verify') }}" class="mt-3 space-y-3" data-turbo="false">
                    @csrf
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" placeholder="6-digit code"
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 tracking-widest focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <button type="submit" class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Confirm new email</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.account.email.request') }}" class="mt-4 space-y-3" data-turbo="false">
                    @csrf
                    <input type="password" name="current_password" placeholder="Current password" autocomplete="current-password"
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <input type="email" name="new_email" placeholder="New email"
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <button type="submit" class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Send code to new email</button>
                </form>
            @endif
        </div>

        {{-- MOBILE --}}
        <div class="admin-panel p-5">
            <h2 class="text-lg font-medium text-slate-100">Mobile number</h2>
            <p class="mt-1 text-sm"><span class="text-slate-400">Current:</span> <span class="text-slate-100">{{ $admin->mobile_number }}</span> <span class="text-xs text-slate-500">(login id)</span></p>

            @if ($admin->pending_mobile)
                <p class="mt-4 text-sm text-amber-200">Pending change to <strong>{{ $admin->pending_mobile }}</strong>. Enter the code we emailed to your address.</p>
                <form method="POST" action="{{ route('admin.account.mobile.verify') }}" class="mt-3 space-y-3" data-turbo="false">
                    @csrf
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" placeholder="6-digit code"
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 tracking-widest focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <button type="submit" class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Confirm new mobile</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.account.mobile.request') }}" class="mt-4 space-y-3" data-turbo="false">
                    @csrf
                    <input type="password" name="current_password" placeholder="Current password" autocomplete="current-password"
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <input type="text" name="new_mobile" inputmode="numeric" maxlength="10" placeholder="New 10-digit mobile"
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <button type="submit" class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Send code to my email</button>
                </form>
                @unless ($admin->email_verified_at)
                    <p class="mt-2 text-xs text-amber-300">Verify your email first — the confirmation code is sent there.</p>
                @endunless
            @endif
        </div>
    </div>

    <div class="admin-panel p-5 mt-6">
        <h2 class="text-lg font-medium text-slate-100">Two-factor &amp; recovery</h2>
        <div class="mt-3 flex flex-wrap gap-3 text-sm">
            <a href="{{ route('admin.2fa.enroll') }}" class="text-amber-400 hover:text-amber-300">Manage authenticator (TOTP)</a>
            <a href="{{ route('admin.recovery-codes.show') }}" class="text-amber-400 hover:text-amber-300">Recovery codes</a>
        </div>
        <p class="mt-3 text-xs text-slate-500">Changing your email or mobile signs out all your other sessions. The old contact is alerted by email.</p>
    </div>
</x-super-admin.layout>
