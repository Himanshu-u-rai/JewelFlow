<x-app-layout>
    <x-page-header
        class="categories-page-header"
        :title="__('Categories')"
        :subtitle="__('Manage product categories and sub-categories')"
    >
        <x-slot:actions>
            @can('catalog.manage')
            <button onclick="openAddCategoryModal()"
                class="btn btn-success btn-sm categories-add-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                {{ __('Add Category') }}
            </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner categories-index-page">

        @unless(auth()->user()->can('catalog.manage'))
            @include('partials.view-only-banner', ['permission' => 'catalog.manage', 'message' => 'category management'])
        @endunless

        @if($categories->isEmpty())
            <x-empty-state
                :title="__('No Categories Yet')"
                :description="__('Create your first category to organize your products')"
            >
                <x-slot:action>
                    @can('catalog.manage')
                    <button onclick="openAddCategoryModal()" class="btn btn-success btn-sm">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        {{ __('Add First Category') }}
                    </button>
                    @endcan
                </x-slot:action>
            </x-empty-state>
        @else
            <div id="categories-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 categories-grid">
                @foreach($categories as $category)
                    @include('categories._category-card', ['category' => $category])
                @endforeach
            </div>
        @endif
    </div>

    {{-- ==================== ADD CATEGORY MODAL ==================== --}}
    <div id="addCategoryModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeAddCategoryModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Add New Category') }}</h3>
                </div>
                <form method="POST" action="{{ route('categories.store') }}" class="p-6" data-turbo-stream>
                    @csrf
                    <input type="hidden" name="_intent" value="add_category">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('_intent') === 'add_category' ? old('name') : '' }}" required
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 @if($errors->has('name') && old('_intent') === 'add_category') border-red-500 @endif"
                               placeholder="{{ __('e.g., Rings, Necklaces, Bangles') }}">
                        @if($errors->has('name') && old('_intent') === 'add_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeAddCategoryModal()"
                                class="btn btn-secondary btn-sm">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="btn btn-success btn-sm">
                            {{ __('Add Category') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ==================== ADD SUB-CATEGORY MODAL ==================== --}}
    <div id="addSubCategoryModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeAddSubCategoryModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Add Sub-Category') }}</h3>
                    <p class="text-sm text-gray-500 mt-1">{{ __('Adding to:') }} <span id="parentCategoryName" class="font-medium text-gray-700"></span></p>
                </div>
                <form method="POST" action="{{ route('sub-categories.store') }}" class="p-6">
                    @csrf
                    <input type="hidden" name="_intent" value="add_sub_category">
                    <input type="hidden" name="category_id" id="subCategoryCategoryId" value="{{ old('_intent') === 'add_sub_category' ? old('category_id') : '' }}">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Sub-Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('_intent') === 'add_sub_category' ? old('name') : '' }}" required
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 @if($errors->has('name') && old('_intent') === 'add_sub_category') border-red-500 @endif"
                               placeholder="{{ __('e.g., Daily Wear, Bridal, Traditional') }}">
                        @if($errors->has('name') && old('_intent') === 'add_sub_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                        @if($errors->has('category_id'))
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('category_id') }}</p>
                        @endif
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeAddSubCategoryModal()"
                                class="btn btn-secondary btn-sm">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="btn btn-success btn-sm">
                            {{ __('Add Sub-Category') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ==================== EDIT CATEGORY MODAL ==================== --}}
    <div id="editCategoryModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeEditCategoryModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Rename Category') }}</h3>
                </div>
                <form method="POST" id="editCategoryForm" class="p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_intent" value="edit_category">
                    <input type="hidden" name="_edit_category_id" id="editCategoryIdInput" value="{{ old('_edit_category_id') }}">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="editCategoryName"
                               value="{{ old('_intent') === 'edit_category' ? old('name') : '' }}" required
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 @if($errors->has('name') && old('_intent') === 'edit_category') border-red-500 @endif">
                        @if($errors->has('name') && old('_intent') === 'edit_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeEditCategoryModal()"
                                class="btn btn-secondary btn-sm">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="btn btn-success btn-sm">
                            {{ __('Save Changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ==================== EDIT SUB-CATEGORY MODAL ==================== --}}
    <div id="editSubCategoryModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeEditSubCategoryModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-xl">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Rename Sub-Category') }}</h3>
                </div>
                <form method="POST" id="editSubCategoryForm" class="p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_intent" value="edit_sub_category">
                    <input type="hidden" name="_edit_sub_id" id="editSubIdInput" value="{{ old('_edit_sub_id') }}">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Sub-Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="editSubCategoryName"
                               value="{{ old('_intent') === 'edit_sub_category' ? old('name') : '' }}" required
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 @if($errors->has('name') && old('_intent') === 'edit_sub_category') border-red-500 @endif">
                        @if($errors->has('name') && old('_intent') === 'edit_sub_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeEditSubCategoryModal()"
                                class="btn btn-secondary btn-sm">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="btn btn-success btn-sm">
                            {{ __('Save Changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const categoryBaseUrl  = @js(url('categories'));
        const subBaseUrl       = @js(url('sub-categories'));

        // ---- Add Category ----
        function openAddCategoryModal() {
            document.getElementById('addCategoryModal').classList.remove('hidden');
        }
        function closeAddCategoryModal() {
            document.getElementById('addCategoryModal').classList.add('hidden');
        }

        // ---- Add Sub-Category ----
        // Reads category id/name from data attributes — never from raw JS string interpolation.
        function openAddSubCategoryModal(categoryId, categoryName) {
            document.getElementById('subCategoryCategoryId').value = categoryId;
            document.getElementById('parentCategoryName').textContent = categoryName;
            document.getElementById('addSubCategoryModal').classList.remove('hidden');
        }
        function closeAddSubCategoryModal() {
            document.getElementById('addSubCategoryModal').classList.add('hidden');
        }

        // ---- Edit Category ----
        function openEditCategoryModal(id, name) {
            document.getElementById('editCategoryForm').action = categoryBaseUrl + '/' + id;
            document.getElementById('editCategoryIdInput').value = id;
            document.getElementById('editCategoryName').value = name;
            document.getElementById('editCategoryModal').classList.remove('hidden');
            document.getElementById('editCategoryName').focus();
        }
        function closeEditCategoryModal() {
            document.getElementById('editCategoryModal').classList.add('hidden');
        }

        // ---- Edit Sub-Category ----
        function openEditSubCategoryModal(id, name) {
            document.getElementById('editSubCategoryForm').action = subBaseUrl + '/' + id;
            document.getElementById('editSubIdInput').value = id;
            document.getElementById('editSubCategoryName').value = name;
            document.getElementById('editSubCategoryModal').classList.remove('hidden');
            document.getElementById('editSubCategoryName').focus();
        }
        function closeEditSubCategoryModal() {
            document.getElementById('editSubCategoryModal').classList.add('hidden');
        }

        // ---- Escape key closes any open modal ----
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddCategoryModal();
                closeAddSubCategoryModal();
                closeEditCategoryModal();
                closeEditSubCategoryModal();
            }
        });

        function syncCategoryCollapseMode() {
            const page = document.querySelector('.categories-index-page');
            if (!page) return;

            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            page.querySelectorAll('.categories-card').forEach((card) => {
                const toggleBtn = card.querySelector('[data-category-toggle]');
                if (!toggleBtn) return;

                if (isMobile) {
                    card.classList.add('is-collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                } else {
                    card.classList.remove('is-collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                }
            });
        }

        function toggleCategoryCard(card) {
            if (!window.matchMedia('(max-width: 768px)').matches) return;

            const toggleBtn = card.querySelector('[data-category-toggle]');
            if (!toggleBtn) return;

            const collapsed = card.classList.toggle('is-collapsed');
            toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }

        function initCategoriesPage() {
            const page = document.querySelector('.categories-index-page');
            if (!page) return;

            @php $intent = old('_intent'); @endphp

            @if($intent === 'add_category')
                openAddCategoryModal();

            @elseif($intent === 'add_sub_category')
                @php
                    $oldCatId   = (int) old('category_id');
                    $oldCatName = $categories->firstWhere('id', $oldCatId)?->name ?? '';
                @endphp
                openAddSubCategoryModal({{ $oldCatId }}, @js($oldCatName));

            @elseif($intent === 'edit_category')
                @php
                    $oldEditCatId   = (int) old('_edit_category_id');
                    $oldEditCatName = old('name', $categories->firstWhere('id', $oldEditCatId)?->name ?? '');
                @endphp
                openEditCategoryModal({{ $oldEditCatId }}, @js($oldEditCatName));

            @elseif($intent === 'edit_sub_category')
                @php $oldEditSubId = (int) old('_edit_sub_id'); @endphp
                openEditSubCategoryModal({{ $oldEditSubId }}, @js(old('name', '')));
            @endif

            page.querySelectorAll('.categories-card').forEach((card) => {
                const toggleBtn = card.querySelector('[data-category-toggle]');
                const toggleSurface = card.querySelector('[data-category-toggle-surface]');
                if (!toggleBtn || toggleBtn.dataset.categoryToggleBound === '1') return;

                toggleBtn.addEventListener('click', () => {
                    toggleCategoryCard(card);
                });
                toggleBtn.dataset.categoryToggleBound = '1';

                if (toggleSurface && toggleSurface.dataset.categorySurfaceBound !== '1') {
                    toggleSurface.addEventListener('click', (event) => {
                        if (event.target.closest('.categories-card-actions')) return;
                        toggleCategoryCard(card);
                    });
                    toggleSurface.dataset.categorySurfaceBound = '1';
                }
            });

            syncCategoryCollapseMode();
        }

        if (window.__categoriesPageTurboInitHandler) {
            document.removeEventListener('turbo:load', window.__categoriesPageTurboInitHandler);
        }
        window.__categoriesPageTurboInitHandler = initCategoriesPage;
        document.addEventListener('turbo:load', window.__categoriesPageTurboInitHandler);

        if (window.__categoriesPageMediaQuery && window.__categoriesPageMediaHandler) {
            window.__categoriesPageMediaQuery.removeEventListener('change', window.__categoriesPageMediaHandler);
        }
        window.__categoriesPageMediaQuery = window.matchMedia('(max-width: 768px)');
        window.__categoriesPageMediaHandler = syncCategoryCollapseMode;
        window.__categoriesPageMediaQuery.addEventListener('change', window.__categoriesPageMediaHandler);

        initCategoriesPage();
    </script>
</x-app-layout>
