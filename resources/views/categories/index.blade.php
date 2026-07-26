<x-app-layout>
    <x-page-header
        class="categories-page-header"
        :title="__('Categories')"
        :subtitle="__('Manage product categories and sub-categories')"
    >
        <x-slot:actions>
            @can('catalog.manage')
            <button onclick="openAddCategoryModal()"
                class="categories-add-btn inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
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

        @if($totalCategories === 0)
            {{-- Genuinely no categories in this shop — distinct from a search miss below. --}}
            <div class="categories-empty-card rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <div class="categories-empty-icon mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h10"/>
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-slate-900">{{ __('No Categories Yet') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">{{ __('Create your first category to organize jewellery stock and sub-categories.') }}</p>
                @can('catalog.manage')
                    <button onclick="openAddCategoryModal()" class="categories-primary-action mt-5 inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        {{ __('Add First Category') }}
                    </button>
                @endcan
            </div>
        @else
            {{-- KPI cards are shop-wide totals from the controller, never the
                 count of what's on the current page or filtered by search. --}}
            <div class="categories-kpi-grid mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="categories-kpi-card rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center gap-3">
                        <div class="categories-kpi-icon">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h10"/>
                            </svg>
                        </div>
                        <div>
                            <p class="categories-kpi-label">{{ __('Categories') }}</p>
                            <p class="categories-kpi-value">{{ number_format($totalCategories) }}</p>
                        </div>
                    </div>
                </div>
                <div class="categories-kpi-card rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center gap-3">
                        <div class="categories-kpi-icon">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>
                            </svg>
                        </div>
                        <div>
                            <p class="categories-kpi-label">{{ __('Sub-Categories') }}</p>
                            <p class="categories-kpi-value">{{ number_format($totalSubCategories) }}</p>
                        </div>
                    </div>
                </div>
                <div class="categories-kpi-card rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-center gap-3">
                        <div class="categories-kpi-icon categories-kpi-icon--quiet">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M4.93 19h14.14a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16a2 2 0 001.73 3z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="categories-kpi-label">{{ __('Need Setup') }}</p>
                            <p class="categories-kpi-value">{{ number_format($emptyCategoryCount) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Server-side search (GET). Blank q behaves as the normal index. --}}
            <form method="GET" action="{{ route('categories.index') }}" class="categories-search-form mb-5 flex flex-wrap items-center gap-2">
                <label for="categorySearchInput" class="sr-only">{{ __('Search categories or sub-categories') }}</label>
                <input type="search" name="q" id="categorySearchInput" value="{{ $q }}" maxlength="100"
                       placeholder="{{ __('Search categories or sub-categories…') }}"
                       class="categories-input min-w-[12rem] flex-1"
                       aria-label="{{ __('Search categories or sub-categories') }}">
                <button type="submit"
                        class="categories-primary-action inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                    {{ __('Search') }}
                </button>
                @if($q !== '')
                    <a href="{{ route('categories.index') }}"
                       class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                        {{ __('Clear') }}
                    </a>
                @endif
            </form>

            @if($q !== '')
                <p class="mb-4 text-sm text-slate-500">{{ __('Showing results for “:q”', ['q' => $q]) }}</p>
            @endif

            @if($categories->isEmpty())
                {{-- Search miss — explicitly different from the empty-shop state above. --}}
                <div class="categories-empty-card rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('No matching categories') }}</h2>
                    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">{{ __('No categories or sub-categories match your search.') }}</p>
                    <a href="{{ route('categories.index') }}" class="categories-primary-action mt-5 inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                        {{ __('Clear search') }}
                    </a>
                </div>
            @endif

            <div id="categories-list" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 categories-grid">
                @foreach($categories as $category)
                    @include('categories._category-card', ['category' => $category])
                @endforeach
            </div>

            @if($categories->hasPages())
                <div class="categories-pagination mt-6">
                    {{ $categories->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- ==================== ADD CATEGORY MODAL ==================== --}}
    <div id="addCategoryModal" class="categories-modal fixed inset-0 z-50 hidden">
        <div class="categories-modal-overlay absolute inset-0 bg-slate-950/55" onclick="closeAddCategoryModal()"></div>
        <div class="categories-modal-panel absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 px-4">
            <div class="categories-modal-card bg-white shadow-xl">
                <div class="categories-modal-head border-b border-slate-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Add New Category') }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Create a parent group for jewellery stock.') }}</p>
                </div>
                <form method="POST" action="{{ route('categories.store') }}" class="categories-modal-body p-6" data-turbo-stream>
                    @csrf
                    <input type="hidden" name="_intent" value="add_category">
                    <div class="mb-4">
                        <label for="addCategoryName" class="categories-field-label">{{ __('Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="addCategoryName" value="{{ old('_intent') === 'add_category' ? old('name') : '' }}" required
                               class="categories-input @if($errors->has('name') && old('_intent') === 'add_category') border-red-500 @endif"
                               placeholder="{{ __('e.g., Rings, Necklaces, Bangles') }}">
                        @if($errors->has('name') && old('_intent') === 'add_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                    </div>
                    <div class="categories-modal-actions flex justify-end gap-3">
                        <button type="button" onclick="closeAddCategoryModal()"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="categories-primary-action inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Add Category') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ==================== ADD SUB-CATEGORY MODAL ==================== --}}
    <div id="addSubCategoryModal" class="categories-modal fixed inset-0 z-50 hidden">
        <div class="categories-modal-overlay absolute inset-0 bg-slate-950/55" onclick="closeAddSubCategoryModal()"></div>
        <div class="categories-modal-panel absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 px-4">
            <div class="categories-modal-card bg-white shadow-xl">
                <div class="categories-modal-head border-b border-slate-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Add Sub-Category') }}</h3>
                    <p class="text-sm text-slate-500 mt-1">{{ __('Adding to:') }} <span id="parentCategoryName" class="font-semibold text-slate-700"></span></p>
                </div>
                <form method="POST" action="{{ route('sub-categories.store') }}" class="categories-modal-body p-6">
                    @csrf
                    <input type="hidden" name="_intent" value="add_sub_category">
                    <input type="hidden" name="category_id" id="subCategoryCategoryId" value="{{ old('_intent') === 'add_sub_category' ? old('category_id') : '' }}">
                    <div class="mb-4">
                        <label for="addSubCategoryName" class="categories-field-label">{{ __('Sub-Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="addSubCategoryName" value="{{ old('_intent') === 'add_sub_category' ? old('name') : '' }}" required
                               class="categories-input @if($errors->has('name') && old('_intent') === 'add_sub_category') border-red-500 @endif"
                               placeholder="{{ __('e.g., Daily Wear, Bridal, Traditional') }}">
                        @if($errors->has('name') && old('_intent') === 'add_sub_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                        @if($errors->has('category_id'))
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('category_id') }}</p>
                        @endif
                    </div>
                    <div class="categories-modal-actions flex justify-end gap-3">
                        <button type="button" onclick="closeAddSubCategoryModal()"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="categories-primary-action inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Add Sub-Category') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ==================== EDIT CATEGORY MODAL ==================== --}}
    <div id="editCategoryModal" class="categories-modal fixed inset-0 z-50 hidden">
        <div class="categories-modal-overlay absolute inset-0 bg-slate-950/55" onclick="closeEditCategoryModal()"></div>
        <div class="categories-modal-panel absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 px-4">
            <div class="categories-modal-card bg-white shadow-xl">
                <div class="categories-modal-head border-b border-slate-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Rename Category') }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Update parent category name.') }}</p>
                </div>
                <form method="POST" id="editCategoryForm" class="categories-modal-body p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_intent" value="edit_category">
                    <input type="hidden" name="_edit_category_id" id="editCategoryIdInput" value="{{ old('_edit_category_id') }}">
                    <div class="mb-4">
                        <label for="editCategoryName" class="categories-field-label">{{ __('Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="editCategoryName"
                               value="{{ old('_intent') === 'edit_category' ? old('name') : '' }}" required
                               class="categories-input @if($errors->has('name') && old('_intent') === 'edit_category') border-red-500 @endif">
                        @if($errors->has('name') && old('_intent') === 'edit_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                    </div>
                    <div class="categories-modal-actions flex justify-end gap-3">
                        <button type="button" onclick="closeEditCategoryModal()"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="categories-primary-action inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Save Changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ==================== EDIT SUB-CATEGORY MODAL ==================== --}}
    <div id="editSubCategoryModal" class="categories-modal fixed inset-0 z-50 hidden">
        <div class="categories-modal-overlay absolute inset-0 bg-slate-950/55" onclick="closeEditSubCategoryModal()"></div>
        <div class="categories-modal-panel absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 px-4">
            <div class="categories-modal-card bg-white shadow-xl">
                <div class="categories-modal-head border-b border-slate-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Rename Sub-Category') }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Update category detail name.') }}</p>
                </div>
                <form method="POST" id="editSubCategoryForm" class="categories-modal-body p-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_intent" value="edit_sub_category">
                    <input type="hidden" name="_edit_sub_id" id="editSubIdInput" value="{{ old('_edit_sub_id') }}">
                    <div class="mb-4">
                        <label for="editSubCategoryName" class="categories-field-label">{{ __('Sub-Category Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="editSubCategoryName"
                               value="{{ old('_intent') === 'edit_sub_category' ? old('name') : '' }}" required
                               class="categories-input @if($errors->has('name') && old('_intent') === 'edit_sub_category') border-red-500 @endif">
                        @if($errors->has('name') && old('_intent') === 'edit_sub_category')
                            <p class="mt-1 text-sm text-red-600">{{ $errors->first('name') }}</p>
                        @endif
                    </div>
                    <div class="categories-modal-actions flex justify-end gap-3">
                        <button type="button" onclick="closeEditSubCategoryModal()"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit"
                                class="categories-primary-action inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition !min-h-[44px] focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
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

        function showCategoryModal(id, focusSelector = 'input[name="name"]') {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('hidden');
            document.body.classList.add('categories-modal-open');
            window.setTimeout(() => {
                const input = modal.querySelector(focusSelector);
                if (!input) return;
                input.focus();
                if (input.select) input.select();
            }, 30);
        }

        function hideCategoryModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('hidden');
            if (!document.querySelector('.categories-modal:not(.hidden)')) {
                document.body.classList.remove('categories-modal-open');
            }
        }

        // ---- Add Category ----
        function openAddCategoryModal() {
            showCategoryModal('addCategoryModal');
        }
        function closeAddCategoryModal() {
            hideCategoryModal('addCategoryModal');
        }

        // ---- Add Sub-Category ----
        // Reads category id/name from data attributes — never from raw JS string interpolation.
        function openAddSubCategoryModal(categoryId, categoryName) {
            document.getElementById('subCategoryCategoryId').value = categoryId;
            document.getElementById('parentCategoryName').textContent = categoryName;
            showCategoryModal('addSubCategoryModal');
        }
        function closeAddSubCategoryModal() {
            hideCategoryModal('addSubCategoryModal');
        }

        // ---- Edit Category ----
        function openEditCategoryModal(id, name) {
            document.getElementById('editCategoryForm').action = categoryBaseUrl + '/' + id;
            document.getElementById('editCategoryIdInput').value = id;
            document.getElementById('editCategoryName').value = name;
            showCategoryModal('editCategoryModal', '#editCategoryName');
        }
        function closeEditCategoryModal() {
            hideCategoryModal('editCategoryModal');
        }

        // ---- Edit Sub-Category ----
        function openEditSubCategoryModal(id, name) {
            document.getElementById('editSubCategoryForm').action = subBaseUrl + '/' + id;
            document.getElementById('editSubIdInput').value = id;
            document.getElementById('editSubCategoryName').value = name;
            showCategoryModal('editSubCategoryModal', '#editSubCategoryName');
        }
        function closeEditSubCategoryModal() {
            hideCategoryModal('editSubCategoryModal');
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
            document.body.classList.remove('categories-modal-open');

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
