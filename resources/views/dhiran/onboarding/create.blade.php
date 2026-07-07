<x-dhiran-layout title="Set up your Dhiran business">
    <div class="dob-wrap">
        <div class="dob-head">
            <h1>Set up your Dhiran business</h1>
            <p>Just a few details to get your pledge loan shop ready.</p>
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
