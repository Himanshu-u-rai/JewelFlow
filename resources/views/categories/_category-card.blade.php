<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden categories-card" style="border-left:4px solid #0d9488;" data-deletable-row>
    <!-- Category Header -->
    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center categories-card-head" style="background: linear-gradient(135deg, #f0fdfa 0%, #ffffff 100%);">
        <div class="categories-card-title-wrap">
            <h3 class="font-semibold text-gray-900">{{ $category->name }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ __(':count sub-categories', ['count' => $category->subCategories->count()]) }}</p>
        </div>
        <div class="flex items-center gap-1 categories-card-actions">
            <button
                type="button"
                class="p-2 rounded-lg text-gray-500 hover:text-slate-700 hover:bg-slate-100 transition-colors categories-mobile-toggle"
                data-category-toggle
                aria-label="{{ __('Toggle sub-categories') }}"
                aria-expanded="true"
            >
                <svg class="w-4 h-4 categories-mobile-toggle-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9l6 6 6-6"/>
                </svg>
            </button>
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openAddSubCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="p-2 rounded-lg text-gray-500 hover:text-amber-700 hover:bg-amber-50 transition-colors"
                title="{{ __('Add Sub-Category') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
            </button>
            <button
                data-category-id="{{ $category->id }}"
                data-category-name="{{ $category->name }}"
                onclick="openEditCategoryModal(this.dataset.categoryId, this.dataset.categoryName)"
                class="p-2 rounded-lg text-gray-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                title="{{ __('Rename Category') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                </svg>
            </button>
            <form method="POST" action="{{ route('categories.destroy', $category) }}"
                  data-confirm-message="{{ __('Delete this category and all its sub-categories?') }}" class="inline"
                  data-ajax-delete>
                @csrf
                @method('DELETE')
                <button type="submit" class="p-2 rounded-lg text-gray-500 hover:text-red-600 hover:bg-red-50 transition-colors" title="{{ __('Delete Category') }}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <!-- Sub-Categories List -->
    <div class="p-4 categories-sub-list">
        @if($category->subCategories->isEmpty())
            <p class="text-sm text-gray-400 text-center py-4">{{ __('No sub-categories') }}</p>
        @else
            <ul class="space-y-2">
                @foreach($category->subCategories as $sub)
                    <li class="flex items-center justify-between px-3 py-2 rounded-lg group border border-gray-200 hover:border-amber-200 hover:bg-amber-50/50 transition-colors" data-deletable-row>
                        <span class="text-sm text-gray-700">{{ $sub->name }}</span>
                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button
                                data-sub-id="{{ $sub->id }}"
                                data-sub-name="{{ $sub->name }}"
                                onclick="openEditSubCategoryModal(this.dataset.subId, this.dataset.subName)"
                                class="p-1 text-gray-400 hover:text-blue-600 transition-colors"
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
                                <button type="submit" class="p-1 text-gray-400 hover:text-red-600 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
