<div class="categories-card is-collapsed overflow-hidden rounded-2xl border border-slate-200 bg-white" data-deletable-row>
    <!-- Category Header -->
    <div class="categories-card-head flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4" data-category-toggle-surface>
        <div class="categories-card-title-wrap min-w-0">
            <div class="flex min-w-0 items-center gap-3">
                <span class="categories-card-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-xl">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h10"/>
                    </svg>
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
                class="categories-icon-btn categories-mobile-toggle"
                data-category-toggle
                aria-label="{{ __('Toggle sub-categories') }}"
                aria-expanded="false"
            >
                <svg class="w-4 h-4 categories-mobile-toggle-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9l6 6 6-6"/>
                </svg>
            </button>
            @can('catalog.manage')
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openAddSubCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="categories-icon-btn categories-icon-btn--primary"
                title="{{ __('Add Sub-Category') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
            </button>
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openEditCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="categories-icon-btn hover:text-slate-900 hover:bg-slate-100"
                title="{{ __('Rename Category') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                </svg>
            </button>
            <form method="POST" action="{{ route('categories.destroy', $category) }}"
                  data-confirm-message="{{ __('Delete this category?') }}" class="inline"
                  data-ajax-delete>
                @csrf
                @method('DELETE')
                <button type="submit" class="categories-icon-btn hover:text-red-600 hover:bg-red-50" title="{{ __('Delete Category') }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
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
                    <li class="group flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-2.5 transition-colors hover:border-amber-200 hover:bg-amber-50/50" data-deletable-row>
                        <span class="min-w-0 truncate text-sm font-medium text-slate-700">{{ $sub->name }}</span>
                        @can('catalog.manage')
                        <div class="flex shrink-0 items-center gap-1 categories-sub-actions">
                            <button
                                data-sub-id="{{ $sub->id }}"
                                data-sub-name="{{ $sub->name }}"
                                onclick="openEditSubCategoryModal(this.dataset.subId, this.dataset.subName)"
                                class="categories-sub-icon-btn hover:text-slate-900"
                                title="{{ __('Rename') }}">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                            </button>
                            <form method="POST" action="{{ route('sub-categories.destroy', $sub) }}"
                                data-confirm-message="{{ __('Delete this sub-category?') }}" class="inline"
                                data-ajax-delete>
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="categories-sub-icon-btn hover:text-red-600">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
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
