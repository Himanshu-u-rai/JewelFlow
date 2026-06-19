<x-dhiran-layout title="Set up your Dhiran business">
    <style>
        .dob-wrap { max-width: 560px; margin: 12px auto 0; }
        .dob-head { text-align: center; margin-bottom: 24px; }
        .dob-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 6px; }
        .dob-head p { color: var(--dh-muted); font-size: 15px; margin: 0; }
        .dob-card {
            background: #fff;
            border: 1px solid var(--dh-line);
            border-radius: 18px;
            box-shadow: 0 14px 40px rgba(20, 40, 75, 0.08);
            padding: 26px;
        }
        .dob-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .dob-field { display: flex; flex-direction: column; gap: 6px; }
        .dob-field.full { grid-column: 1 / -1; }
        .dob-field label { font-size: 13px; font-weight: 700; color: #334155; }
        .dob-field .opt { color: var(--dh-muted); font-weight: 500; }
        .dob-field input {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid var(--dh-line);
            border-radius: 10px;
            font-size: 14px;
            background: #fff;
            transition: border-color 150ms var(--dh-ease), box-shadow 150ms var(--dh-ease);
        }
        .dob-field input:focus {
            outline: 0;
            border-color: var(--dh-gold);
            box-shadow: 0 0 0 3px rgba(244, 163, 0, 0.16);
        }
        .dob-err { color: #b91c1c; font-size: 12px; font-weight: 600; }
        .dob-foot { margin-top: 22px; }
        .dob-btn {
            width: 100%;
            padding: 14px 18px;
            border: 0; border-radius: 12px;
            background: linear-gradient(135deg, var(--dh-gold), var(--dh-gold-deep));
            color: #fff; font-size: 15px; font-weight: 700;
            cursor: pointer;
            transition: transform 150ms var(--dh-ease);
            box-shadow: 0 8px 20px rgba(217, 139, 0, 0.28);
        }
        .dob-btn:active { transform: scale(0.97); }
        @media (max-width: 560px) { .dob-grid { grid-template-columns: 1fr; } }
    </style>

    <div class="dob-wrap">
        <div class="dob-head">
            <h1>Set up your Dhiran business</h1>
            <p>Just a few details to get your gold-loan shop ready.</p>
        </div>

        <div class="dob-card">
            <form method="POST" action="{{ route('dhiran.onboarding.store') }}" data-turbo-frame="_top">
                @csrf
                <div class="dob-grid">
                    <div class="dob-field full">
                        <label for="name">Business name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus maxlength="255" placeholder="e.g. Sharma Gold Finance">
                        @error('name') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="owner_name">Owner name</label>
                        <input id="owner_name" name="owner_name" type="text" value="{{ old('owner_name') }}" required maxlength="255" placeholder="Full name">
                        @error('owner_name') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="phone">Mobile number</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required pattern="[0-9]{10}" maxlength="10" placeholder="10-digit number">
                        @error('phone') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field full">
                        <label for="address">Shop address</label>
                        <input id="address" name="address" type="text" value="{{ old('address') }}" required maxlength="500" placeholder="Street, area, landmark">
                        @error('address') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="city">City</label>
                        <input id="city" name="city" type="text" value="{{ old('city') }}" required maxlength="100">
                        @error('city') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="state">State</label>
                        <input id="state" name="state" type="text" value="{{ old('state') }}" required maxlength="100">
                        @error('state') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="gst_number">GSTIN <span class="opt">(optional)</span></label>
                        <input id="gst_number" name="gst_number" type="text" value="{{ old('gst_number') }}" maxlength="50">
                        @error('gst_number') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="dob-field">
                        <label for="loan_number_prefix">Loan receipt prefix <span class="opt">(optional)</span></label>
                        <input id="loan_number_prefix" name="loan_number_prefix" type="text" value="{{ old('loan_number_prefix') }}" maxlength="12" placeholder="e.g. SGF">
                        @error('loan_number_prefix') <span class="dob-err">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="dob-foot">
                    <button type="submit" class="dob-btn">Finish setup &amp; open dashboard</button>
                </div>
            </form>
        </div>
    </div>
</x-dhiran-layout>
