<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <title>Jewelflow</title>
    @include('partials.favicon')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        :root {
            --gold-50:  #fffbeb;
            --gold-100: #fef3c7;
            --gold-200: #fde68a;
            --gold-300: #fcd34d;
            --gold-400: #fbbf24;
            --gold-500: #f59e0b;
            --gold-600: #d97706;
            --gold-700: #b45309;
            --ink-900:  #0f172a;
            --ink-700:  #334155;
            --ink-500:  #64748b;
            --line:     #e7e8ee;
            --ease-out: cubic-bezier(0.22, 1, 0.36, 1);
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            min-height: 100svh;
            background: #f4f5fa;
            color: var(--ink-900);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ─── Split Layout ───────────────────────────── */
        .auth-shell {
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            min-height: 100vh;
            min-height: 100svh;
        }

        /* ─── Left Panel: Brand / Illustration ───────── */
        .auth-left {
            position: relative;
            background:
                radial-gradient(120% 90% at 80% -10%, rgba(251,191,36,0.10) 0%, transparent 45%),
                linear-gradient(155deg, #1c1626 0%, #0f172a 48%, #161327 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px;
            overflow: hidden;
        }

        /* Soft gold glow accents */
        .auth-left::before {
            content: '';
            position: absolute;
            top: -140px;
            right: -120px;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(251,191,36,0.10) 0%, transparent 70%);
        }

        .auth-left::after {
            content: '';
            position: absolute;
            bottom: -100px;
            left: -90px;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(251,191,36,0.07) 0%, transparent 70%);
        }

        .left-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            max-width: 440px;
        }

        .hero-illustration {
            display: flex;
            justify-content: center;
            width: 100%;
            margin-bottom: 40px;
        }

        .hero-illustration svg {
            width: 220px;
            height: 220px;
            filter: drop-shadow(0 0 44px rgba(251,191,36,0.18));
        }

        .left-brand {
            font-size: 34px;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.6px;
            margin: 0 0 10px;
        }
        /* Solid gold emphasis, not gradient-clip text */
        .left-brand span { color: var(--gold-400); }

        .left-tagline {
            font-size: 16px;
            color: rgba(255,255,255,0.62);
            line-height: 1.65;
            margin: 0 0 40px;
            max-width: 34ch;
        }

        /* Trust badges */
        .trust-row {
            display: flex;
            gap: 28px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .trust-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.55);
            font-weight: 500;
            letter-spacing: 0.1px;
        }

        .trust-badge svg {
            width: 17px;
            height: 17px;
            color: var(--gold-400);
            opacity: 0.85;
        }

        /* Floating gold particles */
        .particle {
            position: absolute;
            border-radius: 50%;
            background: var(--gold-400);
            opacity: 0;
            animation: float-up 8s var(--ease-out) infinite;
            pointer-events: none;
        }
        .particle:nth-child(1) { width: 4px; height: 4px; left: 15%; animation-delay: 0s; animation-duration: 7s; }
        .particle:nth-child(2) { width: 3px; height: 3px; left: 35%; animation-delay: 2s; animation-duration: 9s; }
        .particle:nth-child(3) { width: 5px; height: 5px; left: 60%; animation-delay: 4s; animation-duration: 6s; }
        .particle:nth-child(4) { width: 3px; height: 3px; left: 80%; animation-delay: 1s; animation-duration: 8s; }
        .particle:nth-child(5) { width: 4px; height: 4px; left: 50%; animation-delay: 3s; animation-duration: 10s; }
        .particle:nth-child(6) { width: 2px; height: 2px; left: 25%; animation-delay: 5s; animation-duration: 7s; }

        @keyframes float-up {
            0%   { transform: translateY(100svh) scale(0); opacity: 0; }
            10%  { opacity: 0.4; }
            90%  { opacity: 0.1; }
            100% { transform: translateY(-100px) scale(1.2); opacity: 0; }
        }

        /* ─── Right Panel: Form ──────────────────────── */
        .auth-right {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 32px;
            position: relative;
            overflow-y: auto;
            background:
                radial-gradient(90% 70% at 50% -5%, rgba(245,158,11,0.06) 0%, transparent 55%),
                #f4f5fa;
        }

        .form-container {
            width: 100%;
            max-width: 416px;
        }

        /* Elevated form card - the main premium lift */
        .form-card {
            position: relative;
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 36px 34px 30px;
            box-shadow:
                0 1px 2px rgba(15, 23, 42, 0.04),
                0 12px 28px -8px rgba(15, 23, 42, 0.10),
                0 32px 64px -24px rgba(180, 83, 9, 0.10);
        }
        /* Hairline gold accent along the card's top edge */
        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 26px;
            right: 26px;
            height: 2px;
            border-radius: 9999px;
            background: linear-gradient(90deg, transparent, var(--gold-400), var(--gold-300), transparent);
        }

        .form-header {
            margin-bottom: 22px;
            text-align: center;
        }

        .form-header-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 4px;
        }

        .form-header-brand svg {
            width: 30px;
            height: 30px;
        }

        .form-header-brand h1 {
            font-size: 23px;
            font-weight: 800;
            color: var(--ink-900);
            letter-spacing: -0.4px;
            margin: 0;
        }

        .form-header p {
            font-size: 13px;
            color: var(--ink-500);
            margin: 2px 0 0;
            letter-spacing: 0.1px;
        }

        .gold-divider {
            height: 1px;
            background: var(--line);
            border: 0;
            margin: 20px 0 22px;
        }

        /* Field labels */
        .form-container label,
        .form-card label {
            color: var(--ink-700);
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.1px;
        }

        /* Inputs - depth + refined focus */
        .form-container input[type="tel"],
        .form-container input[type="text"],
        .form-container input[type="password"],
        .form-container input[type="email"],
        .form-container select {
            width: 100%;
            border: 1.5px solid var(--line);
            border-radius: 13px;
            padding: 13px 15px;
            font-size: 14.5px;
            color: var(--ink-900);
            background: #fff;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
            transition: border-color 0.18s var(--ease-out),
                        box-shadow 0.18s var(--ease-out),
                        background 0.18s var(--ease-out);
        }

        .form-container input::placeholder { color: #79839a; }

        .form-container input[type="tel"]:hover,
        .form-container input[type="text"]:hover,
        .form-container input[type="password"]:hover,
        .form-container input[type="email"]:hover {
            border-color: #d7dae2;
        }

        .form-container input[type="tel"]:focus,
        .form-container input[type="text"]:focus,
        .form-container input[type="password"]:focus,
        .form-container input[type="email"]:focus,
        .form-container select:focus {
            outline: none;
            border-color: var(--gold-500);
            box-shadow:
                inset 0 1px 2px rgba(15, 23, 42, 0.03),
                0 0 0 4px rgba(245, 158, 11, 0.14);
            background: #fffdf8;
        }

        /* Primary button - solid gold, tactile */
        .form-container button[type="submit"],
        .form-container .btn-primary,
        .form-container button.inline-flex {
            width: 100%;
            justify-content: center;
            background: var(--gold-600);
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 14.5px;
            letter-spacing: 0.2px;
            text-transform: none;
            padding: 13px 22px;
            border-radius: 13px;
            cursor: pointer;
            box-shadow: 0 6px 16px -4px rgba(217, 119, 6, 0.45);
            transition: background 0.16s var(--ease-out),
                        transform 0.12s var(--ease-out),
                        box-shadow 0.16s var(--ease-out);
        }

        .form-container button[type="submit"]:hover,
        .form-container .btn-primary:hover,
        .form-container button.inline-flex:hover {
            background: var(--gold-700);
            box-shadow: 0 10px 24px -6px rgba(217, 119, 6, 0.5);
        }

        .form-container button[type="submit"]:active,
        .form-container .btn-primary:active,
        .form-container button.inline-flex:active {
            transform: scale(0.98);
        }

        .auth-footer {
            text-align: center;
            font-size: 12px;
            color: #8b94a6;
            margin-top: 22px;
            letter-spacing: 0.2px;
        }

        /* ─── Reduced motion ─────────────────────────── */
        @media (prefers-reduced-motion: reduce) {
            .particle { display: none; }
            .hero-illustration svg animate { display: none; }
            * { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; }
        }

        /* ─── Tablet / mobile ────────────────────────── */
        @media (max-width: 900px) {
            .auth-shell {
                grid-template-columns: 1fr;
                min-height: 100svh;
            }
            .auth-left { display: none; }
            .auth-right {
                min-height: 100svh;
                justify-content: center;
                align-items: stretch;
                padding: 28px 18px;
                background:
                    radial-gradient(90% 50% at 50% 0%, rgba(245,158,11,0.08) 0%, transparent 60%),
                    #f4f5fa;
            }
            .form-container { max-width: 440px; margin: auto; }
            .form-card { padding: 30px 24px 26px; border-radius: 22px; }
        }

        @media (max-width: 380px) {
            .form-card { padding: 26px 18px 22px; }
        }
    </style>
</head>

<body>

<div class="auth-shell">
    <!-- ═══ Left: Brand panel ═══ -->
    <div class="auth-left">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>

        <div class="left-content">
            <div class="hero-illustration">
                <svg viewBox="0 0 220 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="110" cy="110" r="95" stroke="url(#goldGrad)" stroke-width="1" opacity="0.2"/>
                    <circle cx="110" cy="110" r="80" stroke="url(#goldGrad)" stroke-width="1.5" opacity="0.3"/>
                    <ellipse cx="110" cy="120" rx="52" ry="48" stroke="url(#goldGrad)" stroke-width="6" fill="none"/>
                    <ellipse cx="110" cy="120" rx="52" ry="48" stroke="url(#goldShine)" stroke-width="2" fill="none" opacity="0.5"/>
                    <ellipse cx="110" cy="120" rx="42" ry="38" stroke="rgba(251,191,36,0.15)" stroke-width="1" fill="none"/>
                    <path d="M92 82 L110 56 L128 82 L110 110 Z" fill="url(#diamondGrad)" opacity="0.9"/>
                    <path d="M92 82 L110 56 L128 82" fill="none" stroke="url(#goldGrad)" stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M92 82 L110 110 L128 82" fill="none" stroke="rgba(251,191,36,0.3)" stroke-width="0.8"/>
                    <line x1="110" y1="56" x2="110" y2="110" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/>
                    <line x1="92" y1="82" x2="128" y2="82" stroke="rgba(251,191,36,0.4)" stroke-width="0.8"/>
                    <path d="M100 82 L110 110" stroke="rgba(255,255,255,0.08)" stroke-width="0.5"/>
                    <path d="M120 82 L110 110" stroke="rgba(255,255,255,0.08)" stroke-width="0.5"/>
                    <circle cx="75" cy="68" r="2" fill="#fcd34d" opacity="0.6">
                        <animate attributeName="opacity" values="0.6;0.1;0.6" dur="3s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="148" cy="72" r="1.5" fill="#fde68a" opacity="0.5">
                        <animate attributeName="opacity" values="0.5;0.15;0.5" dur="2.5s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="60" cy="105" r="1.5" fill="#fcd34d" opacity="0.4">
                        <animate attributeName="opacity" values="0.4;0.1;0.4" dur="4s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="158" cy="110" r="2" fill="#fde68a" opacity="0.4">
                        <animate attributeName="opacity" values="0.4;0.05;0.4" dur="3.5s" repeatCount="indefinite"/>
                    </circle>
                    <g transform="translate(135, 58)" opacity="0.5">
                        <line x1="0" y1="-4" x2="0" y2="4" stroke="#fcd34d" stroke-width="1" stroke-linecap="round"/>
                        <line x1="-4" y1="0" x2="4" y2="0" stroke="#fcd34d" stroke-width="1" stroke-linecap="round"/>
                        <animate attributeName="opacity" values="0.5;0.1;0.5" dur="2s" repeatCount="indefinite"/>
                    </g>
                    <g transform="translate(82, 50)" opacity="0.35">
                        <line x1="0" y1="-3" x2="0" y2="3" stroke="#fde68a" stroke-width="0.8" stroke-linecap="round"/>
                        <line x1="-3" y1="0" x2="3" y2="0" stroke="#fde68a" stroke-width="0.8" stroke-linecap="round"/>
                        <animate attributeName="opacity" values="0.35;0.08;0.35" dur="3s" repeatCount="indefinite"/>
                    </g>
                    <path d="M30 160 Q110 200 190 160" stroke="rgba(251,191,36,0.08)" stroke-width="1" fill="none"/>
                    <path d="M40 175 Q110 210 180 175" stroke="rgba(251,191,36,0.05)" stroke-width="1" fill="none"/>
                    <defs>
                        <linearGradient id="goldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#fcd34d"/>
                            <stop offset="50%" stop-color="#f59e0b"/>
                            <stop offset="100%" stop-color="#d97706"/>
                        </linearGradient>
                        <linearGradient id="goldShine" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="transparent"/>
                            <stop offset="40%" stop-color="#fef3c7"/>
                            <stop offset="60%" stop-color="#fef3c7"/>
                            <stop offset="100%" stop-color="transparent"/>
                        </linearGradient>
                        <linearGradient id="diamondGrad" x1="110" y1="56" x2="110" y2="110" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#fef3c7" stop-opacity="0.9"/>
                            <stop offset="50%" stop-color="#fbbf24" stop-opacity="0.6"/>
                            <stop offset="100%" stop-color="#d97706" stop-opacity="0.3"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>

            <h2 class="left-brand">Jewel<span>flow</span></h2>
            <p class="left-tagline">
                The complete jewellery business platform. Manage your inventory,
                sales, and customers in one place.
            </p>

            <div class="trust-row">
                <div class="trust-badge">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Secure
                </div>
                <div class="trust-badge">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Fast
                </div>
                <div class="trust-badge">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                    Cloud
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Right: Form panel ═══ -->
    <div class="auth-right">
        <div class="form-container">
            <div class="form-card">
                <div class="form-header">
                    <div class="form-header-brand">
                        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8 13L16 4L24 13L16 28Z" fill="url(#smallDiamond)"/>
                            <path d="M8 13L16 4L24 13" stroke="#d97706" stroke-width="1.2" stroke-linejoin="round"/>
                            <line x1="8" y1="13" x2="24" y2="13" stroke="#f59e0b" stroke-width="0.8" opacity="0.6"/>
                            <defs>
                                <linearGradient id="smallDiamond" x1="16" y1="4" x2="16" y2="28" gradientUnits="userSpaceOnUse">
                                    <stop offset="0%" stop-color="#fcd34d"/>
                                    <stop offset="100%" stop-color="#d97706"/>
                                </linearGradient>
                            </defs>
                        </svg>
                        <h1>Jewelflow</h1>
                    </div>
                    <p>Jewellery Business Management</p>
                </div>

                <hr class="gold-divider">

                {{ $slot }}
            </div>

            <div class="auth-footer">
                © {{ date('Y') }} Jewelflow · Secure · Professional
            </div>
        </div>
    </div>
</div>

</body>
</html>
