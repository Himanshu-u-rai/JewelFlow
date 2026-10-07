<x-dynamic-component :component="$owner->realm === 'dhiran' ? 'dhiran-layout' : 'app-layout'">
    @if($owner->realm !== 'dhiran')
        <x-page-header title="Product preferences" />
    @endif
    <div class="content-inner product-preferences" data-turbo="false">
        <style>
            .product-preferences { max-width:880px; margin:0 auto; padding:24px 16px; color:#18322b; }
            .product-preferences * { box-sizing:border-box; }
            .product-preferences h1 { font-size:clamp(23px,4vw,32px); line-height:1.2; font-weight:750; margin:16px 0 12px; }
            .product-preferences h2 { font-size:19px; font-weight:700; margin:0 0 10px; }
            .product-preferences p { line-height:1.65; margin:8px 0 16px; }
            .product-preferences section { padding:20px; border:1px solid #dbe5e0; border-radius:14px; background:#fff; margin:18px 0; }
            .product-preferences .pp-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
            .product-preferences :is(button,.pp-back) { display:inline-flex; align-items:center; min-height:44px; max-width:100%; border:1px solid #496d5f; border-radius:8px; padding:10px 16px; font:inherit; font-size:14px; line-height:1.5; background:#194d3c; color:white; text-decoration:none; cursor:pointer; }
            .product-preferences .pp-back { color:#194d3c; background:white; }
            .product-preferences label { display:block; margin:14px 0 6px; font-weight:600; }
            .product-preferences input:not([type=checkbox]) { display:block; width:100%; min-height:44px; padding:10px; border:1px solid #768b81; border-radius:8px; font:inherit; }
            .product-preferences .pp-consent { display:flex; align-items:flex-start; gap:10px; font-weight:400; line-height:1.5; min-height:44px; }
            .product-preferences input[type=checkbox] { flex:0 0 20px; width:20px; height:20px; margin-top:3px; }
            .product-preferences .pp-notice { padding:14px; background:#eef8f3; border-left:4px solid #39795d; overflow-wrap:anywhere; }
            .product-preferences .pp-errors { background:#fff0ed; border-color:#b94329; }
            .product-preferences code { display:block; padding:12px; font-size:16px; background:#f1f5f3; overflow-wrap:anywhere; user-select:all; }
            .product-preferences :is(a,button,input):focus-visible { outline:3px solid #b77900; outline-offset:3px; }
            @media(max-width:480px) { .product-preferences section { padding:16px; } .product-preferences .pp-actions > * { width:100%; } }
        </style>
        <a class="pp-back" href="{{ route($owner->realm === 'dhiran' ? 'dhiran.dashboard' : 'dashboard') }}">Back to dashboard</a>
        @if($owner->realm === 'dhiran')
            <h1>Product preferences</h1>
        @endif
        <p>Manage offers for {{ $targetLabel }}. Your accounts, billing and business data stay separate.</p>
        @if(session('status'))
            <p class="pp-notice" role="status">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <div class="pp-notice pp-errors" role="alert">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        <section aria-labelledby="offers-heading">
            <h2 id="offers-heading">Offers for {{ $targetLabel }}</h2>
            <p>{{ $preference->choice ? 'Offers are switched off for this account and shop.' : 'We show an introduction once per campaign. You can switch off future offers here.' }}</p>
            <form method="POST" action="{{ route($prefix.'.preference') }}">
                @csrf
                <div class="pp-actions">
                    <button type="submit" name="choice" value="already_use">I already use {{ $targetLabel }}</button>
                    <button type="submit" name="choice" value="opt_out">Don't show again</button>
                </div>
            </form>
            <p>This preference stops offers without confirming a purchase or changing access.</p>
        </section>
        <section aria-labelledby="recognition-heading">
            <h2 id="recognition-heading">Confirm your other product account (optional)</h2>
            <p>Use this connection only to stop offers for a product you already use. It does not share customers, records, subscriptions or sign-in access.</p>
            @foreach($recognitions as $recognition)
                <form method="POST" action="{{ route($prefix.'.revoke') }}">
                    @csrf
                    <input type="hidden" name="recognition_id" value="{{ $recognition->id }}">
                    <p><strong>{{ $recognition->business }}</strong> — other product confirmed on {{ \Illuminate\Support\Carbon::parse($recognition->confirmed_at)->format('d M Y') }}.</p>
                    <button type="submit">Remove this recognition</button>
                </form>
            @endforeach
            @if(session('recognition_code'))
                <div class="pp-notice" data-turbo-temporary>
                    <p>Open {{ $targetLabel }} yourself and sign in to the intended business as its owner. In Settings → Product preferences, enter this code. It expires 10 minutes after creation.</p>
                    <code aria-label="One-time confirmation code">{{ session('recognition_code') }}</code>
                    <p>Share this only with the owner of the business you intend to confirm. Never share your password.</p>
                </div>
            @endif
            @foreach($pending as $row)
                @if($row->target_user_id)
                    <form method="POST" action="{{ route($prefix.'.finish') }}">
                        @csrf
                        <input type="hidden" name="request_id" value="{{ $row->id }}">
                        <p><strong>{{ $row->target_name }}</strong> approved your request. Confirm only if this is the intended {{ $targetLabel }} business.</p>
                        <label for="finish-password-{{ $row->id }}">Your current product password</label>
                        <input id="finish-password-{{ $row->id }}" name="password" type="password" autocomplete="current-password" required maxlength="1024">
                        <label class="pp-consent"><input type="checkbox" name="consent" value="1" required> I approve recognition of this business only for promotion preferences.</label>
                        <button type="submit">Confirm this business</button>
                    </form>
                @else
                    <p>Waiting for approval in {{ $targetLabel }}. Return and reload this page after entering the code there.</p>
                @endif
                <form method="POST" action="{{ route($prefix.'.cancel') }}" class="pp-actions">
                    @csrf
                    <input type="hidden" name="request_id" value="{{ $row->id }}">
                    <button type="submit">Cancel this request</button>
                </form>
            @endforeach
            @foreach($incoming as $row)
                <form method="POST" action="{{ route($prefix.'.cancel') }}" class="pp-actions">
                    @csrf
                    <input type="hidden" name="request_id" value="{{ $row->id }}">
                    <p>You approved a request awaiting final confirmation in {{ $targetLabel }}.</p>
                    <button type="submit">Withdraw my approval</button>
                </form>
            @endforeach
            <form method="POST" action="{{ route($prefix.'.start') }}">
                @csrf
                <h2 style="margin-top:24px">1. Create a confirmation code</h2>
                <label for="start-password">Your current product password</label>
                <input id="start-password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
                <label class="pp-consent"><input type="checkbox" name="consent" value="1" required> I consent to recognising my other product account for promotion preferences.</label>
                <button type="submit">Create a 10-minute code</button>
            </form>
        </section>
        <section aria-labelledby="code-heading">
            <h2 id="code-heading">2. Have a code from {{ $targetLabel }}?</h2>
            <p>Check that you are signed in to the intended business here. Enter the code from your other product, then return there to give the final confirmation.</p>
            <form method="POST" action="{{ route($prefix.'.approve') }}">
                @csrf
                <label for="recognition-code">Confirmation code</label>
                <input id="recognition-code" name="code" required minlength="40" maxlength="40" pattern="[0-9a-fA-F]{40}" autocomplete="off" spellcheck="false">
                <label for="approve-password">Your current product password</label>
                <input id="approve-password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
                <label class="pp-consent"><input type="checkbox" name="consent" value="1" required> I approve recognition of this business only for promotion preferences.</label>
                <button type="submit">Approve this request</button>
            </form>
        </section>
    </div>
</x-dynamic-component>
