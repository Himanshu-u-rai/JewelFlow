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
            background: #0f172a;
            color: #fff;
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
        .brand-name { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.3px; }
        .brand-name span {
            background: linear-gradient(135deg, var(--gold-300), var(--gold-500));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

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
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 800px; height: 500px;
            background: radial-gradient(circle, rgba(251,191,36,0.06) 0%, transparent 70%);
            pointer-events: none;
        }

        .heading { text-align: center; margin-bottom: 32px; position: relative; z-index: 1; }
        .heading h2 { font-size: 26px; font-weight: 800; margin: 0 0 8px; }
        .heading p { font-size: 15px; color: rgba(255,255,255,0.5); margin: 0; max-width: 520px; }
        .heading .hint { margin-top: 10px; font-size: 13px; color: rgba(251,191,36,0.85); }

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

        .cards {
            display: grid;
            grid-template-columns: repeat(var(--col-count, 3), 1fr);
            gap: 20px;
            max-width: 1100px;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .type-card {
            background: rgba(255,255,255,0.04);
            border: 1.5px solid rgba(255,255,255,0.08);
            padding: 24px 22px 22px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            border-radius: 14px;
        }

        .type-card input[type="checkbox"] {
            position: absolute; opacity: 0; pointer-events: none;
        }

        .type-card:hover {
            border-color: rgba(251,191,36,0.45);
            background: rgba(255,255,255,0.06);
            transform: translateY(-2px);
        }

        .type-card.selected {
            border-color: var(--gold-500);
            background: rgba(251,191,36,0.08);
            box-shadow: 0 12px 32px rgba(0,0,0,0.3), 0 0 0 1px rgba(251,191,36,0.35);
        }

        .check-dot {
            position: absolute;
            top: 14px; right: 14px;
            width: 22px; height: 22px;
            border-radius: 9999px;
            border: 1.5px solid rgba(255,255,255,0.2);
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

        .card-retailer .type-icon     { background: rgba(251,191,36,0.10); }
        .card-manufacturer .type-icon { background: rgba(99,102,241,0.12); }
        .card-dhiran .type-icon       { background: rgba(45,212,191,0.12); }

        .type-card h3 { font-size: 17px; font-weight: 700; color: #fff; margin: 0 0 4px; }
        .type-subtitle {
            font-size: 13px;
            color: rgba(255,255,255,0.48);
            margin-bottom: 14px;
            line-height: 1.5;
        }

        .type-features { flex: 1; display: flex; flex-direction: column; gap: 6px; margin-bottom: 0; }
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12.5px;
            color: rgba(255,255,255,0.62);
            line-height: 1.45;
        }
        .feature-dot { width: 5px; height: 5px; border-radius: 9999px; flex-shrink: 0; margin-top: 7px; }
        .card-retailer .feature-dot     { background: var(--gold-400); }
        .card-manufacturer .feature-dot { background: #818cf8; }
        .card-dhiran .feature-dot       { background: #5eead4; }

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
            background: linear-gradient(135deg, var(--gold-500), var(--gold-600));
            color: #fff;
            letter-spacing: 0.01em;
            transition: all 0.15s;
            min-width: 260px;
        }

        .continue-btn:hover:not(:disabled) {
            box-shadow: 0 10px 32px rgba(217,119,6,0.4);
            transform: translateY(-1px);
        }

        .continue-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .selection-count {
            font-size: 12.5px;
            color: rgba(255,255,255,0.45);
        }

        .footer-note {
            text-align: center;
            padding: 20px 32px 28px;
            font-size: 12px;
            color: rgba(255,255,255,0.28);
            line-height: 1.6;
        }

        @media (max-width: 900px) {
            .cards { --col-count: 1; max-width: 440px; }
        }
    </style>
</head>
<body>

@php
    $enabled = [
        'retailer'     => $retailerEnabled ?? true,
        'manufacturer' => $manufacturerEnabled ?? true,
        'dhiran'       => $dhiranEnabled ?? true,
    ];
    $enabledCount = count(array_filter($enabled));
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
    </div>

    <form method="POST" action="{{ route('shops.choose-type') }}" class="main" id="editionForm">
        @csrf

        <div class="heading">
            <h2>{{ __('Which services do you need?') }}</h2>
            <p>{{ __('Pick one or more. Your choices shape onboarding, billing, and the app you see after login.') }}</p>
            <div class="hint">{{ __('You can select multiple — for example Retailer + Dhiran, or Dhiran only.') }}</div>
        </div>

        @if(session('error'))
            <div class="error-banner">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="error-banner">{{ $errors->first() }}</div>
        @endif

        <div class="cards" style="--col-count: {{ max(1, $enabledCount) }};">
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
                        <svg width="22" height="22" fill="none" stroke="#818cf8" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" stroke-linecap="round" stroke-linejoin="round"/></svg>
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

            @if($enabled['dhiran'])
                <label class="type-card card-dhiran {{ $selected->contains('dhiran') ? 'selected' : '' }}">
                    <input type="checkbox" name="editions[]" value="dhiran" {{ $selected->contains('dhiran') ? 'checked' : '' }}>
                    <div class="check-dot">
                        <svg viewBox="0 0 20 20" fill="none"><path d="M5 10l3 3 7-7" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="type-icon">
                        <svg width="22" height="22" fill="none" stroke="#2dd4bf" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 2v20M5 9h14M5 15h14" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="5" width="18" height="14" rx="2" stroke-linecap="round"/></svg>
                    </div>
                    <h3>{{ __('Dhiran') }}</h3>
                    <p class="type-subtitle">{{ __('Gold loans on pledge — ledger, interest, closure.') }}</p>
                    <div class="type-features">
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Loan & pledge management') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Interest accrual & reminders') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('KYC, closure certificates') }}</span></div>
                        <div class="feature-item"><span class="feature-dot"></span><span>{{ __('Can run standalone') }}</span></div>
                    </div>
                </label>
            @endif
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
        card.addEventListener('click', (e) => {
            if (e.target !== cb) {
                cb.checked = !cb.checked;
            }
            sync();
        });
    });

    sync();
})();
</script>

</body>
</html>
