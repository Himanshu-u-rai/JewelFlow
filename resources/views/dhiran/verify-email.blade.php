<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verify your email — Dhiran</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />
    <style>
        :root { --g:#f4a300; --gd:#d98b00; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --ease:cubic-bezier(0.23,1,0.32,1); }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:#f7f8fb; color:var(--ink);
               min-height:100dvh; display:flex; align-items:center; justify-content:center; padding:20px; }
        .card { width:100%; max-width:440px; background:#fff; border:1px solid var(--line); border-radius:18px;
                box-shadow:0 12px 40px rgba(15,23,42,0.08); padding:32px 28px; }
        .brand { display:flex; align-items:center; gap:10px; margin-bottom:18px; }
        .brand-mark { width:36px; height:36px; border-radius:10px; background:linear-gradient(135deg,var(--g),var(--gd));
                      display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:18px; }
        .brand-name { font-weight:800; font-size:18px; letter-spacing:-0.01em; }
        h1 { font-size:20px; margin:8px 0 4px; }
        .sub { font-size:13.5px; color:var(--muted); line-height:1.5; margin-bottom:20px; }
        label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px; }
        input { width:100%; padding:11px 13px; font-size:14px; border:1.5px solid var(--line); border-radius:11px;
                background:#f8fafc; color:var(--ink); }
        input:focus { outline:none; border-color:var(--g); background:#fff; box-shadow:0 0 0 3px rgba(217,139,0,.12); }
        .btn { width:100%; margin-top:14px; padding:11px; font-size:14px; font-weight:700; border:none; border-radius:11px;
               background:var(--ink); color:#fff; cursor:pointer; transition:transform 140ms var(--ease),opacity 140ms; }
        .btn:active { transform:scale(0.98); }
        .btn:disabled { opacity:.5; cursor:not-allowed; }
        .btn-link { background:none; border:none; color:var(--gd); font-weight:600; font-size:13px; cursor:pointer; padding:0; margin-top:12px; }
        .msg { font-size:13px; margin-top:12px; padding:9px 12px; border-radius:9px; }
        .msg.ok { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .msg.err { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .step2 { display:none; }
        .foot { margin-top:18px; padding-top:16px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; }
        .foot form { margin:0; }
        .foot button { background:none; border:none; color:var(--muted); font-size:12.5px; cursor:pointer; }
    </style>
</head>
<body>
    <div class="card" id="root">
        <div class="brand">
            <span class="brand-mark">D</span>
            <span class="brand-name">Dhiran</span>
        </div>
        <h1>Verify your email</h1>
        <p class="sub">
            Add and verify an email so you can reset your password if you ever forget it.
            You need a verified email to continue using Dhiran.
        </p>

        {{-- Step 1: email --}}
        <div class="step1" id="step1">
            <label for="email">Email address</label>
            <input type="email" id="email" placeholder="you@example.com" value="{{ $user->email ?? '' }}" autocomplete="email">
            <button class="btn" id="sendBtn" onclick="sendOtp()">Send verification code</button>
        </div>

        {{-- Step 2: otp --}}
        <div class="step2" id="step2">
            <label for="otp">Enter the 6-digit code</label>
            <input type="text" id="otp" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code">
            <button class="btn" id="verifyBtn" onclick="verifyOtp()">Verify &amp; continue</button>
            <button class="btn-link" onclick="resendOtp()">Resend code</button>
            <button class="btn-link" onclick="backToEmail()" style="margin-left:14px;">Change email</button>
        </div>

        <div id="msg"></div>

        <div class="foot">
            <span style="font-size:12.5px;color:var(--muted);">{{ $user->mobile_number }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </div>
    </div>

    <script>
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const msg = (text, ok) => { const m=document.getElementById('msg'); m.className='msg '+(ok?'ok':'err'); m.textContent=text; };
        async function post(url, body) {
            const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:JSON.stringify(body) });
            let data={}; try { data = await r.json(); } catch(e){}
            return { ok:r.ok, status:r.status, data };
        }
        function firstError(data){ if(data.message) return data.message; if(data.errors){ const k=Object.keys(data.errors)[0]; return data.errors[k][0]; } return 'Something went wrong. Please try again.'; }

        async function sendOtp() {
            const email = document.getElementById('email').value.trim();
            if (!email) return msg('Please enter your email.', false);
            const btn=document.getElementById('sendBtn'); btn.disabled=true;
            const { ok, data } = await post('{{ route('dhiran.verify-email.send') }}', { email });
            btn.disabled=false;
            if (ok) { document.getElementById('step1').style.display='none'; document.getElementById('step2').style.display='block'; msg(data.message || 'Code sent. Check your inbox.', true); }
            else msg(firstError(data), false);
        }
        async function verifyOtp() {
            const otp = document.getElementById('otp').value.trim();
            if (otp.length!==6) return msg('Enter the 6-digit code.', false);
            const btn=document.getElementById('verifyBtn'); btn.disabled=true;
            const { ok, data } = await post('{{ route('dhiran.verify-email.verify') }}', { otp });
            if (ok) { msg('Email verified! Redirecting…', true); setTimeout(()=>window.location.href='{{ route('dhiran.dashboard') }}', 800); }
            else { btn.disabled=false; msg(firstError(data), false); }
        }
        async function resendOtp() {
            const { ok, data } = await post('{{ route('dhiran.verify-email.resend') }}', {});
            msg(ok ? (data.message || 'Code resent.') : firstError(data), ok);
        }
        function backToEmail(){ document.getElementById('step2').style.display='none'; document.getElementById('step1').style.display='block'; document.getElementById('msg').textContent=''; }
    </script>
</body>
</html>
