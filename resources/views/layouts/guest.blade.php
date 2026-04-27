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
            --gold-50: #fffbeb;
            --gold-100: #fef3c7;
            --gold-200: #fde68a;
            --gold-300: #fcd34d;
            --gold-400: #fbbf24;
            --gold-500: #f59e0b;
            --gold-600: #d97706;
            --gold-700: #b45309;
            --slate-900: #0f172a;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            min-height: 100svh;
            background: #f8f9fc;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ─── Split Layout ───────────────────────────── */
        .auth-shell {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
            min-height: 100svh;
        }

        /* ─── Left Panel: Illustration ───────────────── */
        .auth-left {
            position: relative;
            background: linear-gradient(135deg, #1a1523 0%, #0f172a 40%, #1e1b2e 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px;
            overflow: hidden;
        }

        /* Decorative gold circles */
        .auth-left::before {
            content: '';
            position: absolute;
            top: -120px;
            right: -120px;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.08) 0%, transparent 70%);
        }

        .auth-left::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(251, 191, 36, 0.06) 0%, transparent 70%);
        }

        .left-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            max-width: 420px;
        }

        /* Inline diamond/ring SVG illustration */
        .hero-illustration {
            display: flex;
            justify-content: center;
            width: 100%;
            margin-bottom: 40px;
        }

        .hero-illustration svg {
            width: 220px;
            height: 220px;
            filter: drop-shadow(0 0 40px rgba(251, 191, 36, 0.15));
        }

        .left-brand {
            font-size: 32px;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .left-brand span {
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .left-tagline {
            font-size: 16px;
            color: rgba(255,255,255,0.5);
            line-height: 1.6;
            margin-bottom: 40px;
        }

        /* Trust badges */
        .trust-row {
            display: flex;
            gap: 24px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .trust-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.45);
            font-weight: 500;
        }

        .trust-badge svg {
            width: 18px;
            height: 18px;
            color: var(--gold-400);
            opacity: 0.7;
        }

        /* Floating gold particles */
        .particle {
            position: absolute;
            border-radius: 50%;
            background: var(--gold-400);
            opacity: 0;
            animation: float-up 8s ease-in-out infinite;
        }

        .particle:nth-child(1) { width: 4px; height: 4px; left: 15%; animation-delay: 0s; animation-duration: 7s; }
        .particle:nth-child(2) { width: 3px; height: 3px; left: 35%; animation-delay: 2s; animation-duration: 9s; }
        .particle:nth-child(3) { width: 5px; height: 5px; left: 60%; animation-delay: 4s; animation-duration: 6s; }
        .particle:nth-child(4) { width: 3px; height: 3px; left: 80%; animation-delay: 1s; animation-duration: 8s; }
        .particle:nth-child(5) { width: 4px; height: 4px; left: 50%; animation-delay: 3s; animation-duration: 10s; }
        .particle:nth-child(6) { width: 2px; height: 2px; left: 25%; animation-delay: 5s; animation-duration: 7s; }

        @keyframes float-up {
            0%   { transform: translateY(100vh) scale(0); opacity: 0; }
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
            padding: 48px 24px;
            background: #f8f9fc;
            position: relative;
            overflow-y: auto;
        }

        .auth-right::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--gold-300), var(--gold-500), var(--gold-300));
        }

        @media (min-width: 901px) {
            .auth-right::before { display: none; }
        }

        @media (max-width: 640px) {
            body {
                background: #ffffff;
            }

            .auth-right {
                padding: 20px 16px 28px;
            }
        }

        .form-container {
            width: 100%;
            max-width: 420px;
        }

        .form-header {
            margin-bottom: 32px;
            text-align: center;
        }

        .form-header-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .form-header-brand svg {
            width: 32px;
            height: 32px;
        }

        .form-header-brand h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--slate-900);
            letter-spacing: -0.3px;
        }

        .form-header p {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Gold divider line */
        .gold-divider {
            height: 2px;
            background: linear-gradient(90deg, var(--gold-300), var(--gold-100), transparent);
            border: 0;
            margin: 24px 0;
            border-radius: 9999px;
        }

        /* Form field overrides */
        .form-container input[type="tel"],
        .form-container input[type="text"],
        .form-container input[type="password"],
        .form-container input[type="email"],
        .form-container select {
            border: 1.5px solid #e4e7ec;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #ffffff;
        }

        .form-container input[type="tel"]:focus,
        .form-container input[type="text"]:focus,
        .form-container input[type="password"]:focus,
        .form-container input[type="email"]:focus,
        .form-container select:focus {
            outline: none;
            border-color: var(--gold-500);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.12);
            background: #fff;
        }

        /* Primary button override */
        .form-container button[type="submit"],
        .form-container .btn-primary,
        .form-container button.inline-flex {
            background: linear-gradient(135deg, var(--gold-500), var(--gold-600));
            border: none;
            color: #fff;
            font-weight: 700;
            padding: 13px 24px;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            box-shadow: 0 4px 14px rgba(217, 119, 6, 0.22);
        }

        .form-container button[type="submit"]:hover,
        .form-container .btn-primary:hover,
        .form-container button.inline-flex:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(217, 119, 6, 0.3);
        }

        .form-container button[type="submit"]:active,
        .form-container .btn-primary:active,
        .form-container button.inline-flex:active {
            transform: translateY(0);
        }

        .auth-footer {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 32px;
        }

        /* ─── Global Overrides for btn-black ─────────── */
        .btn-black {
            background: #14213d;
            color: #fff;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-weight: 600;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 8px rgba(20, 33, 61, 0.15);
        }
        .btn-black:hover { background: #0c1425; box-shadow: 0 4px 16px rgba(20, 33, 61, 0.22); }

        @media (max-width: 900px) {
            .auth-shell {
                grid-template-columns: 1fr;
                min-height: 100svh;
            }

            .auth-left {
                display: none;
            }

            .auth-right {
                min-height: 100svh;
                justify-content: flex-start;
                align-items: stretch;
                padding: 28px 18px;
                background: #ffffff;
            }

            .form-container {
                max-width: none;
            }
        }
    </style>
</head>

<body>

<div class="auth-shell">
    <!-- ═══ Left: Illustration Panel ═══ -->
    <div class="auth-left">
        <!-- Floating particles -->
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>

        <div class="left-content">
            <!-- Inline SVG: Gold ring + diamond motif -->
            <div class="hero-illustration">
                <svg viewBox="0 0 220 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Outer glow ring -->
                    <circle cx="110" cy="110" r="95" stroke="url(#goldGrad)" stroke-width="1" opacity="0.2"/>
                    <circle cx="110" cy="110" r="80" stroke="url(#goldGrad)" stroke-width="1.5" opacity="0.3"/>
                    
                    <!-- Main ring band -->
                    <ellipse cx="110" cy="120" rx="52" ry="48" stroke="url(#goldGrad)" stroke-width="6" fill="none"/>
                    <ellipse cx="110" cy="120" rx="52" ry="48" stroke="url(#goldShine)" stroke-width="2" fill="none" opacity="0.5"/>
                    
                    <!-- Inner ring highlight -->
                    <ellipse cx="110" cy="120" rx="42" ry="38" stroke="rgba(251,191,36,0.15)" stroke-width="1" fill="none"/>
                    
                    <!-- Diamond on top -->
                    <path d="M92 82 L110 56 L128 82 L110 110 Z" fill="url(#diamondGrad)" opacity="0.9"/>
                    <path d="M92 82 L110 56 L128 82" fill="none" stroke="url(#goldGrad)" stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M92 82 L110 110 L128 82" fill="none" stroke="rgba(251,191,36,0.3)" stroke-width="0.8"/>
                    <!-- Diamond center line -->
                    <line x1="110" y1="56" x2="110" y2="110" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/>
                    <!-- Diamond cross facets -->
                    <line x1="92" y1="82" x2="128" y2="82" stroke="rgba(251,191,36,0.4)" stroke-width="0.8"/>
                    <path d="M100 82 L110 110" stroke="rgba(255,255,255,0.08)" stroke-width="0.5"/>
                    <path d="M120 82 L110 110" stroke="rgba(255,255,255,0.08)" stroke-width="0.5"/>
                    
                    <!-- Sparkles -->
                    <circle cx="75" cy="68" r="2" fill="var(--gold-300, #fcd34d)" opacity="0.6">
                        <animate attributeName="opacity" values="0.6;0.1;0.6" dur="3s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="148" cy="72" r="1.5" fill="var(--gold-200, #fde68a)" opacity="0.5">
                        <animate attributeName="opacity" values="0.5;0.15;0.5" dur="2.5s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="60" cy="105" r="1.5" fill="var(--gold-300, #fcd34d)" opacity="0.4">
                        <animate attributeName="opacity" values="0.4;0.1;0.4" dur="4s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="158" cy="110" r="2" fill="var(--gold-200, #fde68a)" opacity="0.4">
                        <animate attributeName="opacity" values="0.4;0.05;0.4" dur="3.5s" repeatCount="indefinite"/>
                    </circle>
                    
                    <!-- Star sparkles -->
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

                    <!-- Decorative arcs -->
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
                The complete jewellery business platform.<br>
                Manage inventory, sales &amp; customers — effortlessly.
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

    <!-- ═══ Right: Form Panel ═══ -->
    <div class="auth-right">
        <div class="form-container">
            <div class="form-header">
                <div class="form-header-brand">
                    <!-- Small gold diamond icon -->
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

            <div class="auth-footer">
                © {{ date('Y') }} Jewelflow • Secure • Professional
            </div>
        </div>
    </div>
</div>

</body>
</html>
