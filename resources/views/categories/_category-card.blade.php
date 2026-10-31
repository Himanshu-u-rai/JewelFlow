<div class="categories-card is-collapsed overflow-hidden rounded-2xl border border-slate-200 bg-white" data-category-id="{{ $category->id }}">
    <!-- Category Header -->
    <div class="categories-card-head flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4" data-category-toggle-surface>
        <div class="categories-card-title-wrap min-w-0">
            <div class="flex min-w-0 items-center gap-3">
                <span class="categories-card-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-xl">
                    <x-icon name="menu" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <h3 class="truncate font-semibold text-slate-900">{{ $category->name }}</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">{{ __(':count sub-categories', ['count' => $category->subCategories->count()]) }}</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-1 categories-card-actions">
            <button
                type="button"
                class="categories-icon-btn categories-mobile-toggle !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
                data-category-toggle
                aria-label="{{ __('Toggle sub-categories') }}"
                aria-expanded="false"
            >
                <x-icon name="chevron-down" class="w-4 h-4 categories-mobile-toggle-icon" />
            </button>
            @can('catalog.manage')
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openAddSubCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="categories-icon-btn categories-icon-btn--primary !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
                aria-label="{{ __('Add sub-category to :name', ['name' => $category->name]) }}"
                title="{{ __('Add Sub-Category') }}">
                <x-icon name="plus" class="w-4 h-4" />
            </button>
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openEditCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="categories-icon-btn !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-slate-900 hover:bg-slate-100"
                aria-label="{{ __('Rename category :name', ['name' => $category->name]) }}"
                title="{{ __('Rename Category') }}">
                <x-icon name="pencil" class="w-4 h-4" />
            </button>
            <form method="POST" action="{{ route('categories.destroy', $category) }}"
                  data-confirm-message="{{ __('Delete this unused category? Categories linked to products or subcategories cannot be deleted.') }}" class="inline">
                @csrf
                @method('DELETE')
                <button type="submit"
                    class="categories-icon-btn !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-red-600 hover:bg-red-50"
                    aria-label="{{ __('Delete category :name', ['name' => $category->name]) }}"
                    title="{{ __('Delete Category') }}">
                    <x-icon name="trash-2" class="w-4 h-4" />
                </button>
            </form>
            @endcan
        </div>
    </div>

    <!-- Sub-Categories List -->
    <div class="p-4 categories-sub-list">
        @if($category->subCategories->isEmpty())
            <p class="rounded-xl border border-dashed border-slate-200 bg-slate-50 py-4 text-center text-sm text-slate-400">{{ __('No sub-categories') }}</p>
        @else
            <ul class="space-y-2">
                @foreach($category->subCategories as $sub)
                    <li class="group flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 transition-colors hover:border-amber-200 hover:bg-amber-50/50">
                        <span class="min-w-0 truncate text-sm font-medium text-slate-700">{{ $sub->name }}</span>
                        @can('catalog.manage')
                        <div class="flex shrink-0 items-center gap-1 categories-sub-actions">
                            <button
                                data-sub-id="{{ $sub->id }}"
                                data-sub-name="{{ $sub->name }}"
                                onclick="openEditSubCategoryModal(this.dataset.subId, this.dataset.subName)"
                                class="categories-sub-icon-btn !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-slate-900"
                                aria-label="{{ __('Rename sub-category :name', ['name' => $sub->name]) }}"
                                title="{{ __('Rename') }}">
                                <x-icon name="pencil" class="w-3.5 h-3.5" />
                            </button>
                            <form method="POST" action="{{ route('sub-categories.destroy', $sub) }}"
                                data-confirm-message="{{ __('Delete this sub-category?') }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="categories-sub-icon-btn !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-red-600"
                                    aria-label="{{ __('Delete sub-category :name', ['name' => $sub->name]) }}"
                                    title="{{ __('Delete Sub-Category') }}">
                                    <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                </button>
                            </form>
                        </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
