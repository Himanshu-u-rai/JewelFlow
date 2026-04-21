<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Shop | JewelFlow</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: radial-gradient(1200px 600px at 20% 0%, #eef2ff 0%, transparent 55%),
                        radial-gradient(1200px 600px at 80% 0%, #ecfeff 0%, transparent 55%),
                        #f8fafc;
            min-height: 100vh;
            color: #0f172a;
        }

        .header {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(226,232,240,0.9);
            padding: 18px 32px;
        }
        
        .header h1 {
            font-size: 22px;
            font-weight: 600;
            color: #0f172a;
            letter-spacing: -0.2px;
        }
        
        .header p {
            font-size: 13px;
            color: #64748b;
            margin-top: 2px;
        }

        .container {
            max-width: 1520px;
            margin: 0 auto;
            padding: 22px 18px 30px;
        }

        .shell {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 18px;
            align-items: start;
        }

        .side {
            position: sticky;
            top: 16px;
            align-self: start;
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(226,232,240,0.95);

            padding: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        }

        .side-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .side-sub {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 14px;
            line-height: 1.35;
        }

        .steps {
            display: grid;
            gap: 10px;
            margin-bottom: 14px;
        }

        .step {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 10px 10px;

            border: 1px solid rgba(226,232,240,0.9);
            background: #f8fafc;
        }

        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 9999px;
            display: grid;
            place-items: center;
            font-size: 12px;
            font-weight: 700;
            color: #0d9488;
            background: #eef2ff;
            flex: 0 0 auto;
        }

        .step-text {
            min-width: 0;
        }

        .step-text b {
            display: block;
            font-size: 12px;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .step-text span {
            display: block;
            font-size: 12px;
            color: #64748b;
            line-height: 1.35;
        }

        .note {
            font-size: 12px;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid rgba(226,232,240,0.95);
            padding: 10px 12px;
            border-radius: 12px;
            line-height: 1.35;
        }

        .main {
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(226,232,240,0.95);
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .main-head {
            padding: 16px 18px;
            border-bottom: 1px solid rgba(226,232,240,0.9);
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .main-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.15px;
        }

        .main-desc {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .main-body {
            padding: 14px 18px 18px;
        }
        
        .form-wrapper {
            padding: 0;
            border: none;
            border-radius: 16px;
            background: transparent;
        }

        .section {
            margin-bottom: 0;
            padding: 12px;
            border: 1px solid rgba(226,232,240,0.95);
            border-radius: 16px;
            background: #f8fafc;
        }
        
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: start;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .field {
            margin-bottom: 12px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
            margin-bottom: 12px;
        }
        
        .row.cols-2 {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        
        .row.cols-3 {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        
        .row.cols-4 {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        
        @media (max-width: 1024px) {
            .shell {
                grid-template-columns: 1fr;
            }

            .side {
                position: static;
            }

            .sections-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .sections-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 768px) and (max-width: 1024px) {
            .steps {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 10px;
            }
        }
        
        @media (max-width: 640px) {
            .row, .row.cols-2, .row.cols-3, .row.cols-4 {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 18px 12px 30px;
            }
            
            .header {
                padding: 16px 14px;
            }

            .main-head, .main-body {
                padding-left: 14px;
                padding-right: 14px;
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #334155;
        }

        input, textarea {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid rgba(148,163,184,0.6);
            border-radius: 12px;
            font-size: 15px;
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        @media (min-width: 1280px) {
            .container {
                padding: 30px 34px 40px;
            }

            .shell {
                grid-template-columns: 420px 1fr;
                gap: 22px;
            }

            .side {
                padding: 22px;
            }

            .side-title {
                font-size: 15px;
            }

            .side-sub,
            .note,
            .step-text span {
                font-size: 13px;
            }

            .step-text b {
                font-size: 13px;
            }

            .main-head {
                padding: 20px 26px;
            }

            .main-title {
                font-size: 20px;
            }

            .main-desc {
                font-size: 13px;
            }

            .main-body {
                padding: 18px 26px 26px;
            }

            .sections-grid {
                gap: 16px;
            }

            .section {
                padding: 16px;
            }

            .field {
                margin-bottom: 16px;
            }

            input, textarea {
                padding: 12px 14px;
                font-size: 16px;
            }

            label {
                font-size: 14px;
            }

            .section-title {
                font-size: 15px;
            }
        }

        @media (min-width: 1536px) {
            .container {
                max-width: 1640px;
            }

            .shell {
                grid-template-columns: 460px 1fr;
            }
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        .errors {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 16px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .errors ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .errors li {
            padding: 4px 0;
        }
        
        .errors li:before {
            content: "• ";
            color: #dc2626;
            font-weight: bold;
            margin-right: 4px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(226,232,240,0.9);
        }

        button {
            padding: 12px 32px;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        button:hover {
            background: #0d9488;
        }
        
        button:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>

<div class="header">
    <h1>Create Your Shop</h1>
    <p>Set up your {{ $shopType === 'retailer' ? 'retail' : 'manufacturing' }} jewellery business</p>
</div>

<div class="container">
    <div class="shell">
        <aside class="side">
            <div class="side-title">Quick setup — {{ ucfirst($shopType) }} Edition</div>
            <div class="side-sub">
                @if($shopType === 'retailer')
                    Perfect for shops that buy ready-made jewellery and sell to customers.
                @else
                    For shops that manufacture jewellery from raw gold with lot tracking.
                @endif
                <br>You're 2 minutes away from your dashboard.
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-text">
                        <b>Shop details</b>
                        <span>Name, phone and GST (if applicable).</span>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-text">
                        <b>Address</b>
                        <span>Used on invoices and customer receipts.</span>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-text">
                        <b>Owner</b>
                        <span>Primary admin details for your shop.</span>
                    </div>
                </div>
            </div>

            <div class="note">
                Tip: Use the same mobile number format everywhere (10 digits). You can edit these details later from Settings.
            </div>
        </aside>

        <main class="main">
            <div class="main-head">
                <div class="main-title">Business profile</div>
                <div class="main-desc">These details appear across your app and invoices.</div>
            </div>

            <div class="main-body">
                @if ($errors->any())
                    <div class="errors">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('shops.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="shop_type" value="{{ $shopType }}">

                    <div class="form-wrapper">
                        <div class="sections-grid">
                            <!-- Shop Details Section -->
                            <div class="section">
                                <div class="section-title">
                                    <span></span> Shop Details
                                </div>

                                <div class="field">
                                    <label>Shop Name *</label>
                                    <input type="text" name="name" value="{{ old('name') }}" placeholder="e.g., Golden Jewellers" required>
                                </div>

                                <div class="field">
                                    <label>Shop Phone *</label>
                                    <input type="tel" name="phone" value="{{ old('phone') }}" required pattern="[0-9]{10}" minlength="10" maxlength="10" placeholder="10-digit phone">
                                </div>

                                <div class="field" style="margin-bottom: 0;">
                                    <label>GST Number</label>
                                    <input type="text" name="gst_number" value="{{ old('gst_number') }}" placeholder="e.g., 24AAACC1206D1ZM">
                                </div>

                                @if($shopType === 'manufacturer')
                                <div class="field" style="margin-top: 12px;">
                                    <label>GST Rate (%) *</label>
                                    <input type="number" name="gst_rate" value="{{ old('gst_rate', '3') }}" step="0.01" min="0" max="100" placeholder="3" required>
                                </div>

                                <div class="field" style="margin-bottom: 0;">
                                    <label>Wastage Recovery (%) *</label>
                                    <input type="number" name="wastage_recovery_percent" value="{{ old('wastage_recovery_percent', '100') }}" step="0.01" min="0" max="100" placeholder="100" required>
                                </div>
                                @endif
                            </div>

                            <!-- Address Section -->
                            <div class="section">
                                <div class="section-title">
                                    <span></span> Shop Address
                                </div>

                                <div class="field">
                                    <label>Address Line 1 *</label>
                                    <input type="text" name="address_line1" value="{{ old('address_line1') }}" placeholder="Shop No. 12, XYZ Complex, Main Road" required>
                                </div>

                                <div class="field">
                                    <label>Address Line 2</label>
                                    <input type="text" name="address_line2" value="{{ old('address_line2') }}" placeholder="Near City Mall, Sarkhej Area">
                                </div>

                                <div class="grid-2">
                                    <div class="field">
                                        <label>City *</label>
                                        <input type="text" name="city" value="{{ old('city') }}" placeholder="e.g., Ahmedabad" required>
                                    </div>
                                    <div class="field">
                                        <label>State *</label>
                                        <input type="text" name="state" value="{{ old('state') }}" placeholder="e.g., Gujarat" required>
                                    </div>
                                </div>

                                <div class="grid-2" style="margin-bottom: 0;">
                                    <div class="field">
                                        <label>Pincode *</label>
                                        <input type="text" name="pincode" value="{{ old('pincode') }}" required pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="6-digit">
                                    </div>
                                    <div class="field">
                                        <label>Country</label>
                                        <input type="text" name="country" value="{{ old('country', 'India') }}" placeholder="India">
                                    </div>
                                </div>
                            </div>

                            <!-- Owner Details Section -->
                            <div class="section">
                                <div class="section-title">
                                    <span></span> Owner Details
                                </div>

                                <div class="grid-2">
                                    <div class="field">
                                        <label>First Name *</label>
                                        <input type="text" name="owner_first_name" value="{{ old('owner_first_name') }}" placeholder="First name" required>
                                    </div>
                                    <div class="field">
                                        <label>Last Name *</label>
                                        <input type="text" name="owner_last_name" value="{{ old('owner_last_name') }}" placeholder="Last name" required>
                                    </div>
                                </div>

                                <div class="field">
                                    <label>Mobile Number *</label>
                                    <input type="tel" name="owner_mobile" value="{{ old('owner_mobile') }}" required pattern="[0-9]{10}" minlength="10" maxlength="10" placeholder="10-digit mobile">
                                </div>

                                <div class="field" style="margin-bottom: 0;">
                                    <label>Email Address</label>
                                    <input type="email" name="owner_email" value="{{ old('owner_email') }}" placeholder="owner@example.com">
                                </div>
                            </div>
                        </div>

            <div class="form-actions">
                <button type="submit">Create Shop & Continue →</button>
            </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

</body>
</html>
