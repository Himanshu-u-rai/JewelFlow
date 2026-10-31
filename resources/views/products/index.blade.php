<x-app-layout>
    <x-page-header title="Product Catalog" subtitle="Manage your jewellery product templates">
        <x-slot:actions>
            @can('catalog.manage')
            <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="w-4 h-4 mr-2" />
                Add Product
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner jf-skeleton-host is-loading">

        @unless(auth()->user()->can('catalog.manage'))
            @include('partials.view-only-banner', ['permission' => 'catalog.manage', 'message' => 'product catalog management'])
        @endunless
<!-- Search -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
            <form method="GET" action="{{ route('products.index') }}" class="flex gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <x-icon name="search" size="16" />
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Search by name, design code, category..."
                               class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none"
                               data-suggest="products" autocomplete="off">
                    </div>
                </div>
                @if(filled(request('search')))
                    <a href="{{ route('products.index') }}" class="px-4 py-2 text-gray-600 hover:text-gray-900 text-sm inline-flex items-center"><x-icon name="x" size="14" class="mr-1" />Clear</a>
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
                <x-icon name="package" class="w-16 h-16 mx-auto text-gray-300 mb-4" />
                <h3 class="text-lg font-semibold text-gray-900 mb-2">No Products Yet</h3>
                <p class="text-gray-500 mb-6">Create your first product template</p>
                @can('catalog.manage')
                <a href="{{ route('products.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 text-sm font-medium">
                    Add First Product
                </a>
                @endcan
            </div>
        @else
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] jf-table">
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
                                                <x-icon name="image" class="w-6 h-6 text-gray-300" />
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
                                        @can('inventory.create')
                                        <a href="{{ route('inventory.items.create', ['product_id' => $product->id]) }}"
                                           class="inline-flex items-center px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded hover:bg-emerald-100 text-xs font-medium transition-colors">
                                            <x-icon name="plus" class="w-3 h-3 mr-1" />
                                            Create Stock
                                        </a>
                                        @endcan
                                        <a href="{{ route('products.show', $product) }}"
                                           class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                        @can('catalog.manage')
                                        <a href="{{ route('products.edit', $product) }}"
                                           class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="square-pen" size="16" /><span class="jf-sr-only">Edit</span></a>
                                        @endcan
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
                    <x-icon name="x" class="w-5 h-5 text-gray-600" />
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
