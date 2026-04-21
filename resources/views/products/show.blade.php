<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">{{ $product->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">Product Catalog Detail</p>
        </div>
        <div class="page-actions flex gap-2">
            <a href="{{ route('products.edit', $product) }}" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit</a>
            <a href="{{ route('products.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        @if(session('success'))
            <div class="px-4 py-3 rounded-md border border-green-200 bg-green-50 text-green-700 text-sm">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-0">
                <div class="p-6 border-b lg:border-b-0 lg:border-r border-gray-200 bg-gray-50">
                    @if($product->image)
                        <img src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}" class="w-full max-h-[320px] object-contain rounded-lg bg-white border border-gray-200">
                    @else
                        <div class="w-full h-64 rounded-lg bg-white border border-gray-200 flex items-center justify-center text-sm text-gray-500">No image available</div>
                    @endif
                </div>

                <div class="lg:col-span-2 p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                        <div class="rounded-md border border-gray-200 p-3">
                            <p class="text-xs text-gray-500 uppercase">Purity</p>
                            <p class="text-lg font-semibold text-gray-900 mt-1">{{ $product->default_purity ? $product->default_purity . 'K' : '-' }}</p>
                        </div>
                        <div class="rounded-md border border-gray-200 p-3">
                            <p class="text-xs text-gray-500 uppercase">Approx Weight</p>
                            <p class="text-lg font-semibold text-gray-900 mt-1">{{ $product->approx_weight ? number_format($product->approx_weight, 3) . ' g' : '-' }}</p>
                        </div>
                        <div class="rounded-md border border-gray-200 p-3">
                            <p class="text-xs text-gray-500 uppercase">Making</p>
                            <p class="text-lg font-semibold text-gray-900 mt-1">{{ $product->default_making ? '₹' . number_format($product->default_making, 0) : '-' }}</p>
                        </div>
                        <div class="rounded-md border border-gray-200 p-3">
                            <p class="text-xs text-gray-500 uppercase">Stone</p>
                            <p class="text-lg font-semibold text-gray-900 mt-1">{{ $product->default_stone ? '₹' . number_format($product->default_stone, 0) : '-' }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500">Design Code</p>
                            <p class="font-medium text-gray-900">{{ $product->design_code }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Category</p>
                            <p class="font-medium text-gray-900">{{ $product->category->name ?? 'Uncategorized' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Sub Category</p>
                            <p class="font-medium text-gray-900">{{ $product->subCategory->name ?? '—' }}</p>
                        </div>
                    </div>

                    @if($product->notes)
                        <div class="mt-5 pt-5 border-t border-gray-200">
                            <p class="text-gray-500 text-sm">Description</p>
                            <p class="text-sm text-gray-800 mt-1">{{ $product->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if($product->items && $product->items->count() > 0)
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Items Created from this Product</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ $product->items->count() }} item(s)</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Barcode</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Weight</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($product->items->take(5) as $item)
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $item->barcode }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ number_format($item->gross_weight, 3) }} g</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $item->status == 'in_stock' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                            {{ ucfirst(str_replace('_', ' ', $item->status)) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
