@php
    $k = $karigar ?? null;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Name *</label>
        <input type="text" name="name" value="{{ old('name', $k?->name) }}" required class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Contact Person</label>
        <input type="text" name="contact_person" value="{{ old('contact_person', $k?->contact_person) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Mobile</label>
        <input type="text" name="mobile" value="{{ old('mobile', $k?->mobile) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $k?->email) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="block text-xs font-semibold text-gray-700 mb-1">Address</label>
        <input type="text" name="address" value="{{ old('address', $k?->address) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">City</label>
        <input type="text" name="city" value="{{ old('city', $k?->city) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">State</label>
        <input type="text" name="state" value="{{ old('state', $k?->state) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">PIN code</label>
        <input type="text" name="pincode" value="{{ old('pincode', $k?->pincode) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">GST Number</label>
        <input type="text" name="gst_number" value="{{ old('gst_number', $k?->gst_number) }}" class="w-full rounded-md border-gray-300 text-sm font-mono uppercase">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">PAN</label>
        <input type="text" name="pan_number" value="{{ old('pan_number', $k?->pan_number) }}" class="w-full rounded-md border-gray-300 text-sm font-mono uppercase">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Default Wastage %</label>
        <input type="number" step="0.01" min="0" max="50" name="default_wastage_percent" value="{{ old('default_wastage_percent', $k?->default_wastage_percent) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Default Making/g (₹)</label>
        <input type="number" step="0.01" min="0" name="default_making_per_gram" value="{{ old('default_making_per_gram', $k?->default_making_per_gram) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Opening Balance (₹)</label>
        <input type="number" step="0.01" name="opening_balance" value="{{ old('opening_balance', $k?->opening_balance ?? 0) }}" class="w-full rounded-md border-gray-300 text-sm">
        <p class="text-[10px] text-gray-400 mt-1">Positive = we owe karigar</p>
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">Opening Balance Date</label>
        <input type="date" name="opening_balance_at" value="{{ old('opening_balance_at', $k?->opening_balance_at?->toDateString()) }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="block text-xs font-semibold text-gray-700 mb-1">Notes</label>
        <textarea name="notes" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ old('notes', $k?->notes) }}</textarea>
    </div>
</div>
