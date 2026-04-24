<x-app-layout>
    <x-page-header title="Product Catalog" subtitle="Manage your jewellery product templates">
        <x-slot:actions>
            <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                Add Product
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner jf-skeleton-host is-loading">
        @if(session('success'))
            <div class="mb-6 px-4 py-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm">
                {{ session('success') }}
            </div>
        @endif

        <!-- Search -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
            <form method="GET" action="{{ route('products.index') }}" class="flex gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Search by name, design code, category..."
                               class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none"
                               data-suggest="products" autocomplete="off">
                    </div>
                </div>
                @if(filled(request('search')))
                    <a href="{{ route('products.index') }}" class="px-4 py-2 text-gray-600 hover:text-gray-900 text-sm inline-flex items-center"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                @else
                    <button type="submit" class="btn btn-dark btn-sm">
                        Search
                    </button>
                @endif
            </form>
        </div>

        <!-- Products Grid -->
        @if($products->isEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">No Products Yet</h3>
                <p class="text-gray-500 mb-6">Create your first product template</p>
                <a href="{{ route('products.create') }}" 
                   class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 text-sm font-medium">
                    Add First Product
                </a>
            </div>
        @else
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full min-w-[860px]">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-16">Image</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Product Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Design Code</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Sub-category</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-40">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($products as $product)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-2">
                                    <div class="w-12 h-12 min-w-[48px] max-w-[48px] bg-gray-100 rounded overflow-hidden flex-shrink-0">
                                        @if($product->image)
                                            <button type="button" class="block w-full h-full" onclick="openImageModal('{{ asset('storage/' . $product->image) }}', '{{ $product->name }}')" aria-label="Preview {{ $product->name }} image">
                                                <img src="{{ asset('storage/' . $product->image) }}"
                                                     alt="{{ $product->name }}"
                                                     class="w-12 h-12 object-cover cursor-pointer">
                                            </button>
                                        @else
                                            <div class="w-full h-full flex items-center justify-center">
                                                <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="font-medium text-gray-900">{{ $product->name }}</div>
                                    @if($product->metal_type || $product->default_purity)
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            {{ $product->metal_type ? ucfirst($product->metal_type) : 'Metal pending' }}
                                            @if($product->default_purity)
                                                • {{ $product->default_purity_label }}
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <span class="font-mono text-sm text-gray-600">{{ $product->design_code }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="text-sm text-gray-600">{{ $product->category->name ?? '-' }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="text-sm text-gray-600">{{ $product->subCategory->name ?? '-' }}</span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('inventory.items.create', ['product_id' => $product->id]) }}"
                                           class="inline-flex items-center px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded hover:bg-emerald-100 text-xs font-medium transition-colors">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                            </svg>
                                            Create Stock
                                        </a>
                                        <a href="{{ route('products.show', $product) }}" 
                                           class="inline-flex items-center px-2.5 py-1 bg-amber-50 text-amber-600 rounded hover:bg-amber-100 text-xs font-medium transition-colors">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            View
                                        </a>
                                        <a href="{{ route('products.edit', $product) }}" 
                                           class="inline-flex items-center px-2.5 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200 text-xs font-medium transition-colors">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                            Edit
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            
            <div class="mt-6">{{ $products->links() }}</div>
        @endif
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 z-50 hidden" onclick="closeImageModal()">
        <div class="absolute inset-0 bg-black/80"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="relative max-w-4xl max-h-[90vh]" onclick="event.stopPropagation()">
                <img id="modalImage" src="" alt="Product preview" class="max-w-full max-h-[85vh] object-contain rounded-lg shadow-2xl">
                <button type="button" onclick="closeImageModal()" 
                        class="absolute -top-3 -right-3 p-2 bg-white rounded-full shadow-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                <p id="modalCaption" class="text-white text-center mt-3 text-sm"></p>
            </div>
        </div>
    </div>

    <script>
        function openImageModal(src, caption) {
            document.getElementById('modalImage').src = src;
            document.getElementById('modalImage').alt = caption ? (caption + ' preview') : 'Product preview';
            document.getElementById('modalCaption').textContent = caption;
            document.getElementById('imageModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
            document.body.style.overflow = '';
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeImageModal();
        });
    </script>
</x-app-layout>
