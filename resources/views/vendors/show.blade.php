<x-app-layout>
    <x-page-header class="vendors-show-header ops-treatment-header" :title="$vendor->name" subtitle="Vendor details & associated items">
        <x-slot:actions>
            <a href="{{ route('vendors.edit', $vendor) }}" class="btn btn-secondary btn-sm vendors-show-edit-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                <span class="vendors-show-edit-label-full">Edit Vendor</span>
                <span class="vendors-show-edit-label-short">Edit</span>
            </a>
            <a href="{{ route('vendors.index') }}" class="btn btn-secondary btn-sm vendors-show-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                <span class="vendors-show-back-label-full">Back to Vendors</span>
                <span class="vendors-show-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Vendor Details</h3>
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Business Name</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Contact Person</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->contact_person ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Mobile</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->mobile ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Email</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">City / State</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->city ?? '' }}{{ $vendor->state ? ', ' . $vendor->state : '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">GST Number</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-mono">{{ $vendor->gst_number ?? '—' }}</dd>
                    </div>
                    @if($vendor->address)
                    <div class="md:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Address</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->address }}</dd>
                    </div>
                    @endif
                    @if($vendor->notes)
                    <div class="md:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Notes</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $vendor->notes }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Summary</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Status</span>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $vendor->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                            {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">In-Stock Items</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $vendor->items_count ?? 0 }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-500">Added On</span>
                        <span class="text-sm text-gray-900">{{ $vendor->created_at->format('d M Y') }}</span>
                    </div>
                </div>
            </div>
        </div>

        @if($items->count())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">In-Stock Items from this Vendor</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Barcode</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Weight (g)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">HUID</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($items as $item)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm font-mono">{{ $item->barcode }}</td>
                            <td class="px-6 py-3 text-sm">{{ $item->category }}</td>
                            <td class="px-6 py-3 text-sm text-right">{{ number_format($item->gross_weight, 3) }}</td>
                            <td class="px-6 py-3 text-sm font-mono">{{ $item->huid ?? '—' }}</td>
                            <td class="px-6 py-3 text-sm text-right">₹{{ number_format($item->selling_price, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
