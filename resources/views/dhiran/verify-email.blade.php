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
    @vite(['resources/css/dhiran.css'])
</head>
<body class="dh-verify-body">
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
            <button class="btn-link dh-btn-link-spaced" onclick="backToEmail()">Change email</button>
        </div>

        <div id="msg"></div>

        <div class="foot">
            <span class="dh-verify-mobile">{{ $user->mobile_number }}</span>
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
