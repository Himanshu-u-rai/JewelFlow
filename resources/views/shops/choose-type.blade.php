<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Choose Your Services') }} | Jewelflow</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />
    <style>
        :root {
            --gold-300: #fcd34d;
            --gold-400: #fbbf24;
            --gold-500: #f59e0b;
            --gold-600: #d97706;
            --gold-700: #b45309;
            --slate-900: #0f172a;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            min-height: 100vh;
            /* Bright, welcoming light theme for the first-run onboarding moment.
               Warm gold glow at the top over a soft off-white. */
            background:
                radial-gradient(120% 80% at 50% -10%, rgba(245,158,11,0.10) 0%, transparent 55%),
                #fbf9f5;
            background-attachment: fixed;
            color: #1f2430;
        }

        .page { display: flex; flex-direction: column; min-height: 100vh; }

        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 32px;
        }

        .brand { display: flex; align-items: center; gap: 10px; }
        .brand svg { width: 28px; height: 28px; }
        .brand-name { font-size: 20px; font-weight: 800; color: #1f2430; letter-spacing: -0.3px; }
        .brand-name span { color: var(--gold-600); }

        /* Log out as a proper subtle button, not a bare link. */
        .logout-btn {
            background: #fff;
            border: 1px solid #e7e2d8;
            color: #6b5d44;
            padding: 8px 16px;
            border-radius: 10px;
            font: inherit;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(31, 36, 48, 0.04);
            transition: background 0.16s ease, border-color 0.16s ease, transform 0.12s ease;
        }
        .logout-btn:hover { background: #fbf6ec; border-color: var(--gold-300); color: var(--gold-700); }
        .logout-btn:active { transform: scale(0.97); }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 32px;
            position: relative;
        }

        .main::before {
            content: '';
            position: absolute;
            top: 42%; left: 50%;
            transform: translate(-50%, -50%);
            width: 900px; height: 520px;
            background: radial-gradient(circle, rgba(245,158,11,0.07) 0%, transparent 70%);
            pointer-events: none;
        }

        .heading { text-align: center; margin-bottom: 32px; position: relative; z-index: 1; }
        .heading h2 { font-size: 27px; font-weight: 800; margin: 0 0 8px; color: #1f2430; letter-spacing: -0.4px; }
        .heading p { font-size: 15px; color: #6b7280; margin: 0 auto; max-width: 520px; line-height: 1.5; }
        .heading .hint { margin-top: 12px; font-size: 13px; font-weight: 600; color: var(--gold-700); }

        .error-banner {
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.35);
            color: #fecaca;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 18px;
            max-width: 820px;
            width: 100%;
            text-align: center;
        }

        /* Grid sizes to the number of cards actually rendered, and stays
           centered. Each card has a sensible width so 1 card does not stretch
           across the page and N cards do not pin to one side. */
        .cards {
            display: grid;
            gap: 20px;
            width: 100%;
            justify-content: center;
            position: relative;
            z-index: 1;
            margin: 0 auto;
        }
        .cards[data-count="1"] { grid-template-columns: minmax(0, 380px); max-width: 380px; }
        .cards[data-count="2"] { grid-template-columns: repeat(2, minmax(0, 360px)); max-width: 760px; }
        .cards[data-count="3"] { grid-template-columns: repeat(3, minmax(0, 340px)); max-width: 1080px; }

        .type-card {
            background: #ffffff;
            border: 1.5px solid #ece7dc;
            padding: 24px 22px 22px;
            cursor: pointer;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(31,36,48,0.04), 0 10px 26px -12px rgba(31,36,48,0.10);
        }

        .type-card input[type="checkbox"] {
            position: absolute; opacity: 0; pointer-events: none;
        }

        .type-card:hover {
            border-color: var(--gold-300);
            transform: translateY(-3px);
            box-shadow: 0 1px 2px rgba(31,36,48,0.04), 0 16px 34px -14px rgba(180,83,9,0.22);
        }

        .type-card.selected {
            border-color: var(--gold-500);
            background: #fffdf7;
            box-shadow: 0 0 0 3px rgba(245,158,11,0.18), 0 16px 34px -14px rgba(180,83,9,0.28);
        }

        .check-dot {
            position: absolute;
            top: 14px; right: 14px;
            width: 22px; height: 22px;
            border-radius: 9999px;
            border: 1.5px solid #d9d3c6;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
        }

        .type-card.selected .check-dot {
            background: var(--gold-500);
            border-color: var(--gold-500);
        }

        .check-dot svg { width: 12px; height: 12px; opacity: 0; transition: opacity 0.15s; }
        .type-card.selected .check-dot svg { opacity: 1; }

        .type-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
        }

        .card-retailer .type-icon     { background: rgba(245,158,11,0.14); }
        .card-manufacturer .type-icon { background: rgba(217,119,6,0.16); }
        .card-dhiran .type-icon       { background: rgba(13,148,136,0.14); }

        .type-card h3 { font-size: 17px; font-weight: 700; color: #1f2430; margin: 0 0 4px; }
        .type-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 14px;
            line-height: 1.5;
        }

        .type-features { flex: 1; display: flex; flex-direction: column; gap: 6px; margin-bottom: 0; }
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12.5px;
            color: #4b5260;
            line-height: 1.45;
        }
        .feature-dot { width: 5px; height: 5px; border-radius: 9999px; flex-shrink: 0; margin-top: 7px; }
        .card-retailer .feature-dot     { background: var(--gold-500); }
        .card-manufacturer .feature-dot { background: var(--gold-600); }
        .card-dhiran .feature-dot       { background: #0d9488; }

        .actions {
            margin-top: 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .continue-btn {
            padding: 14px 36px;
            font-size: 15px;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            background: var(--gold-600);
            color: #fff;
            letter-spacing: 0.01em;
            box-shadow: 0 6px 16px -4px rgba(217,119,6,0.45);
            transition: background 0.16s ease, box-shadow 0.16s ease, transform 0.12s ease;
            min-width: 260px;
        }

        .continue-btn:hover:not(:disabled) {
            background: var(--gold-700);
            box-shadow: 0 10px 26px -6px rgba(217,119,6,0.5);
            transform: translateY(-1px);
        }
        .continue-btn:active:not(:disabled) { transform: scale(0.98); }

        .continue-btn:disabled {
            background: #e2dccf;
            color: #fff;
            box-shadow: none;
            cursor: not-allowed;
        }

        .selection-count {
            font-size: 12.5px;
            color: #8b8170;
        }

        .footer-note {
            text-align: center;
            padding: 20px 32px 28px;
            font-size: 12px;
            color: #a39884;
            line-height: 1.6;
        }

        @media (max-width: 820px) {
            .cards,
            .cards[data-count="1"],
            .cards[data-count="2"],
            .cards[data-count="3"] {
                grid-template-columns: minmax(0, 420px);
                max-width: 420px;
            }
            .main { justify-content: flex-start; padding-top: 36px; }
        }
    </style>
</head>
<body>

@php
    $enabled = [
        'retailer'     => $retailerEnabled ?? true,
        'manufacturer' => $manufacturerEnabled ?? true,
        // Dhiran is a separate product served on its own subdomain; not shown here.
        'dhiran'       => false,
    ];
    // The grid must size to the cards ACTUALLY rendered, not the platform's
    // enabled count: otherwise an enabled-but-hidden type (Dhiran) leaves an
    // empty column and the cards pin to the side.
    $visibleTypes = array_keys(array_filter($enabled));
    $cardCount = max(1, count($visibleTypes));
    $selected = collect($selected ?? []);
@endphp

<div class="page">
    <div class="top-bar">
        <div class="brand">
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 13L16 4L24 13L16 28Z" fill="url(#dg)"/>
                <path d="M8 13L16 4L24 13" stroke="#d97706" stroke-width="1.2" stroke-linejoin="round"/>
                <line x1="8" y1="13" x2="24" y2="13" stroke="#f59e0b" stroke-width="0.8" opacity="0.6"/>
                <defs><linearGradient id="dg" x1="16" y1="4" x2="16" y2="28" gradientUnits="userSpaceOnUse"><stop stop-color="#fcd34d"/><stop offset="1" stop-color="#d97706"/></linearGradient></defs>
            </svg>
            <div class="brand-name">Jewel<span>flow</span></div>
        </div>
        {{-- Always allow an escape back to login during onboarding. --}}
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit" class="logout-btn">{{ __('Log out') }}</button>
        </form>
    </div>

    <form method="POST" action="{{ route('shops.choose-type') }}" class="main" id="editionForm">
        @csrf

        <div class="heading">
            <h2>{{ __('Which services do you need?') }}</h2>
            <p>{{ __('Pick one or more. Your choices shape onboarding, billing, and the app you see after login.') }}</p>
            <div class="hint">{{ __('You can select more than one, for example Retailer and Manufacturer.') }}</div>
        </div>

<div class="cards" data-count="{{ $cardCount }}">
            @if($enabled['retailer'])
                <label class="type-card card-retailer {{ $selected->contains('retailer') ? 'selected' : '' }}">
                    <input type="checkbox" name="editions[]" value="retailer" {{ $selected->contains('retailer') ? 'checked' : '' }}>
                    <div class="check-dot">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M5 10l3 3 7-7" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="type-icon">
                        <svg width="22" height="22" fill="none" stroke="#fbbf24" stroke-width="1.8" viewBox="0 0 24 24"><path d="M6 2L3 7v13a1 1 0 001 1h16a1 1 0 001-1V7l-3-5H6z" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 7h18M16 11a4 4 0 01-8 0" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3>{{ __('Retailer') }}</h3>
                    <p class="type-subtitle">{{ __('Buy ready-made jewellery, sell to customers.') }}</p>
                    <div class="type-features">
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Cost & selling price per item') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('POS with live gold rate') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Bulk CSV import') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Profit tracking') }}</span></div>
                    </div>
                </label>
            @endif

            @if($enabled['manufacturer'])
                <label class="type-card card-manufacturer {{ $selected->contains('manufacturer') ? 'selected' : '' }}">
                    <input type="checkbox" name="editions[]" value="manufacturer" {{ $selected->contains('manufacturer') ? 'checked' : '' }}>
                    <div class="check-dot">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M5 10l3 3 7-7" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="type-icon">
                        <svg width="22" height="22" fill="none" stroke="#d97706" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3>{{ __('Manufacturer') }}</h3>
                    <p class="type-subtitle">{{ __('Make jewellery in-house, track lots, wastage, karigars.') }}</p>
                    <div class="type-features">
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Gold lot & metal accounting') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Item creation with wastage') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Fine gold balances') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Manufacturing workflow') }}</span></div>
                    </div>
                </label>
            @endif

            {{-- Dhiran is a separate product served on its own subdomain; not shown here --}}
        </div>

        <div class="actions">
            <button type="submit" class="continue-btn" id="continueBtn" disabled>
                {{ __('Continue →') }}
            </button>
            <div class="selection-count" id="selectionCount">{{ __('Select at least one service') }}</div>
        </div>
    </form>

    <div class="footer-note">
        {{ __('Services can be added or removed later from your settings, or by contacting support.') }}
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('editionForm');
    const cards = form.querySelectorAll('.type-card');
    const btn = document.getElementById('continueBtn');
    const count = document.getElementById('selectionCount');

    function sync() {
        const picks = [];
        cards.forEach(card => {
            const cb = card.querySelector('input[type="checkbox"]');
            if (cb.checked) {
                card.classList.add('selected');
                picks.push(cb.value);
            } else {
                card.classList.remove('selected');
            }
        });
        btn.disabled = picks.length === 0;
        if (picks.length === 0) {
            count.textContent = @json(__('Select at least one service'));
        } else if (picks.length === 1) {
            count.textContent = @json(__('1 service selected'));
        } else {
            count.textContent = picks.length + ' ' + @json(__('services selected'));
        }
    }

    cards.forEach(card => {
        const cb = card.querySelector('input[type="checkbox"]');
        // Card is a <label>, so browser toggles checkbox by default.
        // Listen to checkbox changes only to avoid double-toggle.
        cb.addEventListener('change', sync);
    });

    sync();
})();
</script>

</body>
</html>
