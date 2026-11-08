<x-dhiran-layout title="Add Customer">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Add Customer</h1>
            <p class="text-sm text-gray-500 mt-1">Save a customer's details now; start a loan for them whenever they need one.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.borrowers.index') }}" class="btn btn-secondary btn-sm">Back to Customers</a>
        </div>
    </x-dhiran.page-header>

    @php
        $field = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none';
        $label = 'block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 mb-1';
    @endphp

    <div class="content-inner">
        <form method="POST" action="{{ route('dhiran.customers.store') }}" class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            @if($errors->any())
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
            @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label><span class="{{ $label }}">First name *</span>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required maxlength="255" class="{{ $field }}" autofocus></label>
                <label><span class="{{ $label }}">Last name</span>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="255" class="{{ $field }}"></label>
                <label><span class="{{ $label }}">Mobile number *</span>
                    <input type="tel" name="mobile" value="{{ old('mobile') }}" required maxlength="10" inputmode="numeric" placeholder="10-digit mobile number" class="{{ $field }}"></label>
                <label><span class="{{ $label }}">PAN</span>
                    <input type="text" name="pan" value="{{ old('pan') }}" maxlength="10" placeholder="ABCDE1234F" class="{{ $field }} uppercase"></label>
                <label><span class="{{ $label }}">ID number</span>
                    <input type="text" name="id_number" value="{{ old('id_number') }}" maxlength="50" class="{{ $field }}"></label>
                <label><span class="{{ $label }}">State code</span>
                    <input type="text" name="state_code" value="{{ old('state_code') }}" maxlength="10" class="{{ $field }}"></label>
                <label class="sm:col-span-2"><span class="{{ $label }}">Address</span>
                    <textarea name="address" rows="3" maxlength="500" class="{{ $field }}">{{ old('address') }}</textarea></label>
                <label class="sm:col-span-2"><span class="{{ $label }}">Notes</span>
                    <textarea name="notes" rows="2" maxlength="1000" class="{{ $field }}">{{ old('notes') }}</textarea></label>
            </div>
            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <a href="{{ route('dhiran.borrowers.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-dark btn-sm">Save customer</button>
            </div>
        </form>
    </div>
</x-dhiran-layout>
