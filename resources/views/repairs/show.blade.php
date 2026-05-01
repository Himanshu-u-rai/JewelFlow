<x-app-layout>
    <x-page-header class="repairs-show-header">
        <div>
            <h1 class="page-title">Repair #{{ $repair->repair_number ?? '—' }}</h1>
            <p class="text-sm text-gray-500 mt-1">Repair detail</p>
        </div>
        <div class="page-actions flex items-center gap-2">
            <a href="{{ route('repairs.index') }}" class="btn btn-secondary btn-sm">Back to Repairs</a>
            @if($repair->status !== 'delivered')
                <a href="{{ route('repairs.edit', $repair) }}" class="btn btn-primary btn-sm">Edit</a>
            @endif
        </div>
    </x-page-header>

    <div class="content-inner repairs-show-page">
        <div class="max-w-3xl mx-auto bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500">Customer</p>
                        <p class="font-semibold text-gray-900">{{ $repair->customer?->name ?? '—' }}</p>
                        <p class="text-gray-500">{{ $repair->customer?->mobile ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Status</p>
                        <p class="font-semibold text-gray-900">{{ ucfirst(str_replace('_', ' ', $repair->status)) }}</p>
                        <p class="text-gray-500">Created: {{ optional($repair->created_at)->format('d M Y, h:i A') }}</p>
                    </div>
                </div>
            </div>

            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div>
                        <p class="text-gray-500 text-sm">Item</p>
                        <p class="font-semibold text-gray-900">{{ $repair->item_description }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Description</p>
                        <p class="text-gray-900">{{ $repair->description ?: '—' }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-gray-500 text-sm">Gross Weight</p>
                            <p class="font-semibold text-gray-900">{{ number_format((float) $repair->gross_weight, 3) }} g</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-sm">Purity</p>
                            <p class="font-semibold text-gray-900">{{ $repair->purity ? number_format((float) $repair->purity, 2).'K' : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-sm">Estimated Cost</p>
                            <p class="font-semibold text-gray-900">₹{{ number_format((float) ($repair->estimated_cost ?? 0), 2) }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 text-sm">Final Cost</p>
                            <p class="font-semibold text-gray-900">{{ $repair->final_cost ? '₹'.number_format((float) $repair->final_cost, 2) : '—' }}</p>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-gray-500 text-sm mb-2">Item Photo</p>
                    @php($repairImageUrl = $repair->resolveImageUrl('public'))
                    @if($repairImageUrl)
                        <img src="{{ $repairImageUrl }}" alt="Repair item" class="w-full max-h-80 object-contain rounded-lg border border-gray-200 bg-gray-50">
                    @else
                        <div class="h-52 rounded-lg border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-sm text-gray-500">
                            No photo uploaded
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

