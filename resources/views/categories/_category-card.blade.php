<div class="categories-card is-collapsed" data-category-id="{{ $category->id }}">
    <!-- Category Header -->
    <div class="categories-card-head flex items-center justify-between gap-3" data-category-toggle-surface>
        <div class="categories-card-title-wrap min-w-0">
            <div class="flex min-w-0 items-center gap-3">
                <span class="jf-record-index" data-category-index>{{ $rowNumber ?? ($loop->iteration ?? 1) }}</span>
                <div class="min-w-0">
                    <h3 class="font-semibold text-slate-900">{{ $category->name }}</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">{{ __(':count sub-categories', ['count' => $category->subCategories->count()]) }}</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-1 categories-card-actions">
            <button
                type="button"
                class="categories-icon-btn categories-mobile-toggle jf-row-action !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
                data-tip="Toggle sub-categories"
                data-category-toggle
                aria-label="{{ __('Toggle sub-categories') }}"
                aria-expanded="false"
            >
                <x-icon name="chevron-down" class="w-4 h-4 categories-mobile-toggle-icon" />
            </button>
            @can('categories.manage')
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openAddSubCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="categories-icon-btn categories-icon-btn--primary jf-row-action jf-row-action--edit !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2"
                data-tip="Add sub-category"
                aria-label="{{ __('Add sub-category to :name', ['name' => $category->name]) }}">
                <x-icon name="plus" class="w-4 h-4" />
            </button>
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openEditCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="categories-icon-btn jf-row-action jf-row-action--edit !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-slate-900 hover:bg-slate-100"
                data-tip="Rename category"
                aria-label="{{ __('Rename category :name', ['name' => $category->name]) }}">
                <x-icon name="pencil" class="w-4 h-4" />
            </button>
            <form method="POST" action="{{ route('categories.destroy', $category) }}"
                  data-confirm-message="{{ __('Delete this unused category? Categories linked to products or subcategories cannot be deleted.') }}" class="inline">
                @csrf
                @method('DELETE')
                <button type="submit"
                    class="categories-icon-btn jf-row-action jf-row-action--danger !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-red-600 hover:bg-red-50"
                    data-tip="Delete category"
                    aria-label="{{ __('Delete category :name', ['name' => $category->name]) }}">
                    <x-icon name="trash-2" class="w-4 h-4" />
                </button>
            </form>
            @endcan
        </div>
    </div>

    <!-- Sub-Categories List -->
    <div class="categories-sub-list">
        @if($category->subCategories->isEmpty())
            <p class="stock-tool-muted">{{ __('No sub-categories') }}</p>
        @else
            <ul>
                @foreach($category->subCategories as $sub)
                    <li class="group categories-sub-row">
                        <span class="min-w-0 text-sm font-medium text-slate-700">{{ $sub->name }}</span>
                        @can('categories.manage')
                        <div class="flex shrink-0 items-center gap-1 categories-sub-actions">
                            <button
                                data-sub-id="{{ $sub->id }}"
                                data-sub-name="{{ $sub->name }}"
                                onclick="openEditSubCategoryModal(this.dataset.subId, this.dataset.subName)"
                                class="categories-sub-icon-btn jf-row-action jf-row-action--edit !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-slate-900"
                                data-tip="Rename sub-category"
                                aria-label="{{ __('Rename sub-category :name', ['name' => $sub->name]) }}">
                                <x-icon name="pencil" class="w-3.5 h-3.5" />
                            </button>
                            <form method="POST" action="{{ route('sub-categories.destroy', $sub) }}"
                                data-confirm-message="{{ __('Delete this sub-category?') }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="categories-sub-icon-btn jf-row-action jf-row-action--danger !h-11 !w-11 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 hover:text-red-600"
                                    data-tip="Delete sub-category"
                                    aria-label="{{ __('Delete sub-category :name', ['name' => $sub->name]) }}">
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
