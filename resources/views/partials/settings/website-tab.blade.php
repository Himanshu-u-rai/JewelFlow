<style>
    /* ─── Website-tab scoped styles ─── */
    .cw-toggle-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .cw-toggle-text h4 { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 2px; }
    .cw-toggle-text p { font-size: 13px; color: #64748b; margin: 0; }

    .cw-switch { position: relative; display: inline-block; width: 46px; height: 24px; flex-shrink: 0; }
    .cw-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .cw-switch .cw-slider {
        position: absolute; inset: 0; background: #d1d5db; border-radius: 24px; cursor: pointer;
        transition: background 0.25s;
    }
    .cw-switch .cw-slider::after {
        content: ''; position: absolute; width: 18px; height: 18px; left: 3px; bottom: 3px;
        background: #fff; border-radius: 50%; transition: transform 0.25s; box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .cw-switch input:checked + .cw-slider { background: #0f766e; }
    .cw-switch input:checked + .cw-slider::after { transform: translateX(22px); }

    .cw-url-banner {
        margin-top: 16px; padding: 14px 16px;
        background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px;
        font-size: 13px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    }
    .cw-url-banner strong { color: #166534; white-space: nowrap; }
    .cw-url-banner a { color: #0f766e; font-weight: 600; word-break: break-all; }
    .cw-url-banner .cw-copy-btn {
        margin-left: auto; padding: 5px 12px; font-size: 11px; font-weight: 700;
        background: #fff; border: 1px solid #bbf7d0; border-radius: 8px; cursor: pointer;
        color: #166534; transition: all 0.15s;
    }
    .cw-url-banner .cw-copy-btn:hover { background: #dcfce7; }

    .cw-section { margin-top: 24px; }
    .cw-card {
        background: #ffffff; border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 16px; padding: 20px 22px; margin-top: 12px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
    }
    .cw-card.dashed { border-style: dashed; border-color: rgba(15, 23, 42, 0.14); box-shadow: none; }

    .cw-card-header {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    }

    .cw-visibility-list { display: flex; flex-direction: column; gap: 0; }
    .cw-visibility-item {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        padding: 14px 0; border-bottom: 1px solid rgba(15, 23, 42, 0.06);
    }
    .cw-visibility-item:last-child { border-bottom: none; padding-bottom: 0; }
    .cw-visibility-item:first-child { padding-top: 0; }
    .cw-vis-label { font-size: 14px; font-weight: 600; color: #0f172a; }
    .cw-vis-desc { font-size: 12px; color: #64748b; margin-top: 1px; }

    .cw-cat-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .cw-cat-chip {
        display: inline-flex; align-items: center; padding: 7px 14px;
        border: 1px solid rgba(15, 23, 42, 0.12); border-radius: 9999px;
        font-size: 13px; font-weight: 500; cursor: pointer; background: #fff; transition: all 0.15s;
        user-select: none; color: #475569;
    }
    .cw-cat-chip input { display: none; }
    .cw-cat-chip.selected { border-color: #0f766e; background: #f0fdf4; color: #0f766e; font-weight: 600; }

    .cw-page-row {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
    }
    .cw-page-title { font-size: 14px; font-weight: 600; color: #0f172a; }
    .cw-page-meta { font-size: 12px; color: #94a3b8; margin-top: 2px; }
    .cw-page-meta .cw-published { color: #059669; }
    .cw-page-meta .cw-draft { color: #dc2626; }
    .cw-page-actions { display: flex; gap: 8px; flex-shrink: 0; }

    .cw-btn-sm {
        display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px;
        font-size: 12px; font-weight: 600; color: #374151; background: #fff;
        border: 1px solid rgba(15, 23, 42, 0.12); border-radius: 10px; cursor: pointer;
        transition: all 0.15s;
    }
    .cw-btn-sm:hover { background: #f8fafc; border-color: #cbd5e1; }
    .cw-btn-sm.danger { color: #dc2626; border-color: #fecaca; }
    .cw-btn-sm.danger:hover { background: #fef2f2; }

    .cw-edit-panel {
        margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(15, 23, 42, 0.06);
    }

    .cw-hero-preview {
        display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    }
    .cw-hero-preview img {
        height: 100px; border-radius: 12px; border: 1px solid rgba(15, 23, 42, 0.08);
        object-fit: cover;
    }

    .cw-color-row { display: flex; align-items: center; gap: 10px; }
    .cw-color-input {
        width: 44px; height: 36px; border: 1px solid rgba(15, 23, 42, 0.12); border-radius: 10px;
        cursor: pointer; padding: 2px; background: #fff;
    }

    .cw-add-btn {
        display: flex; align-items: center; justify-content: center; gap: 6px;
        width: 100%; padding: 14px; font-size: 13px; font-weight: 600; color: #64748b;
        background: transparent; border: none; cursor: pointer; transition: color 0.15s;
    }
    .cw-add-btn:hover { color: #0f766e; }

    @media (max-width: 640px) {
        .cw-toggle-row { flex-direction: column; align-items: flex-start; gap: 12px; }
        .cw-page-row { flex-direction: column; align-items: flex-start; }
        .cw-page-actions { width: 100%; }
        .cw-url-banner { flex-direction: column; align-items: flex-start; }
        .cw-url-banner .cw-copy-btn { margin-left: 0; }
    }
</style>

<div class="settings-header">
    <h2 class="settings-title">{{ __('Catalog Website') }}</h2>
    <p class="settings-desc">{{ __('Configure your public catalog website that customers can browse') }}</p>
</div>

@php
    $ws = $catalogWebsiteSettings ?? null;
    $slug = $shop->catalog_slug ?? '';
    $categories = \App\Models\Item::where('status', 'in_stock')
        ->whereNotNull('category')
        ->where('category', '!=', '')
        ->distinct()
        ->orderBy('category')
        ->pluck('category');
@endphp

<form method="POST" action="{{ route('settings.update.catalog-website') }}" enctype="multipart/form-data">
    @csrf
    @method('PATCH')

    {{-- ─── Enable / Disable ─── --}}
    <div class="section-label">{{ __('Website Status') }}</div>
    <div class="cw-card">
        <div class="cw-toggle-row">
            <div class="cw-toggle-text">
                <h4>{{ __('Enable Catalog Website') }}</h4>
                <p>{{ __('When enabled, your shop inventory becomes a browsable website for customers.') }}</p>
            </div>
            <label class="cw-switch">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', $ws?->is_enabled) ? 'checked' : '' }}>
                <span class="cw-slider"></span>
            </label>
        </div>

        @if($slug)
            <div class="cw-url-banner">
                <strong>{{ __('Your catalog URL:') }}</strong>
                <a href="{{ url('/s/' . $slug) }}" target="_blank" rel="noopener" id="cwCatalogUrl">{{ url('/s/' . $slug) }}</a>
                <button type="button" class="cw-copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('cwCatalogUrl').href).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1200)})">Copy</button>
            </div>
        @endif
    </div>

    {{-- ─── URL & Branding ─── --}}
    <div class="cw-section">
        <div class="section-label">{{ __('URL & Branding') }}</div>
        <div class="cw-card">
            <div class="form-row cols-2">
                <div class="field">
                    <label class="field-label">{{ __('Catalog URL Slug') }}</label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="color:#94a3b8;font-size:12px;white-space:nowrap;">{{ url('/s/') }}/</span>
                        <input type="text" name="catalog_slug" value="{{ old('catalog_slug', $slug) }}" class="field-input" placeholder="your-shop-name" style="flex:1">
                    </div>
                    @error('catalog_slug')
                        <span class="field-hint" style="color:#dc2626;">{{ $message }}</span>
                    @enderror
                </div>

                <div class="field">
                    <label class="field-label">{{ __('Accent Color') }}</label>
                    <div class="cw-color-row">
                        <input type="color" name="accent_color" value="{{ old('accent_color', $ws?->accent_color ?? '#8f6a2d') }}" class="cw-color-input" id="cwColorPicker">
                        <input type="text" value="{{ old('accent_color', $ws?->accent_color ?? '#8f6a2d') }}" class="field-input" style="flex:1" id="cwColorText" readonly onclick="document.getElementById('cwColorPicker').click()">
                    </div>
                </div>
            </div>

            <div class="section-divider"></div>

            <div class="form-row" style="grid-template-columns:1fr;">
                <div class="field">
                    <label class="field-label">{{ __('Tagline') }}</label>
                    <input type="text" name="tagline" value="{{ old('tagline', $ws?->tagline) }}" class="field-input" placeholder="{{ __('Exquisite jewelry crafted with passion...') }}" maxlength="150">
                    <span class="field-hint">{{ __('Shown on the homepage hero section. Max 150 characters.') }}</span>
                </div>
            </div>

            <div class="form-row" style="grid-template-columns:1fr;">
                <div class="field">
                    <label class="field-label">{{ __('Meta Description') }}</label>
                    <textarea name="meta_description" class="field-input" rows="2" maxlength="500" placeholder="{{ __('Brief description for search engines and link previews...') }}">{{ old('meta_description', $ws?->meta_description) }}</textarea>
                    <span class="field-hint">{{ __('Used for SEO and WhatsApp link previews.') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Hero Image ─── --}}
    <div class="cw-section">
        <div class="section-label">{{ __('Hero Image') }}</div>
        <div class="cw-card">
            <span class="field-hint" style="margin-bottom:12px;display:block;">{{ __('Optional banner for the homepage. Recommended: 1200 x 600px, JPEG/PNG/WebP, max 5 MB.') }}</span>

            @if($ws?->hero_image_path)
                <div class="cw-hero-preview">
                    <img src="{{ asset('storage/' . $ws->hero_image_path) }}" alt="Hero">
                    <div>
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#64748b;cursor:pointer;">
                            <input type="checkbox" name="remove_hero_image" value="1"> {{ __('Remove current image') }}
                        </label>
                    </div>
                </div>
                <div class="section-divider"></div>
            @endif

            <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp" class="field-input" style="padding:8px 10px;">
        </div>
    </div>

    {{-- ─── Visibility ─── --}}
    <div class="cw-section">
        <div class="section-label">{{ __('Visibility Options') }}</div>
        <div class="cw-card">
            <div class="cw-visibility-list">
                @foreach([
                    ['show_prices',  __('Show Prices'),  __('Display selling prices on product cards and detail pages')],
                    ['show_weights', __('Show Weights'), __('Display gross weight and net metal weight')],
                    ['show_huid',    __('Show HUID'),    __('Display Hallmark Unique Identification number')],
                ] as [$field, $label, $desc])
                    <div class="cw-visibility-item">
                        <div>
                            <div class="cw-vis-label">{{ $label }}</div>
                            <div class="cw-vis-desc">{{ $desc }}</div>
                        </div>
                        <label class="cw-switch">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" name="{{ $field }}" value="1" {{ old($field, $ws?->$field ?? ($field === 'show_huid' ? false : true)) ? 'checked' : '' }}>
                            <span class="cw-slider"></span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ─── Social Links ─── --}}
    <div class="cw-section">
        <div class="section-label">{{ __('Social Links') }}</div>
        <div class="cw-card">
            <div class="form-row">
                <div class="field">
                    <label class="field-label">{{ __('WhatsApp Number') }}</label>
                    <input type="text" name="social_whatsapp" value="{{ old('social_whatsapp', $ws?->social_whatsapp) }}" class="field-input" placeholder="919876543210">
                    <span class="field-hint">{{ __('Country code + number, no spaces') }}</span>
                </div>
                <div class="field">
                    <label class="field-label">{{ __('Instagram URL') }}</label>
                    <input type="url" name="social_instagram" value="{{ old('social_instagram', $ws?->social_instagram) }}" class="field-input" placeholder="https://instagram.com/yourshop">
                </div>
                <div class="field">
                    <label class="field-label">{{ __('Facebook URL') }}</label>
                    <input type="url" name="social_facebook" value="{{ old('social_facebook', $ws?->social_facebook) }}" class="field-input" placeholder="https://facebook.com/yourshop">
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Featured Categories ─── --}}
    @if($categories->isNotEmpty())
        <div class="cw-section">
            <div class="section-label">{{ __('Featured Categories') }}</div>
            <div class="cw-card">
                <span class="field-hint" style="display:block;margin-bottom:10px;">{{ __('Select categories to highlight on the homepage. Leave empty to show all.') }}</span>
                <div class="cw-cat-chips">
                    @foreach($categories as $cat)
                        @php $selected = in_array($cat, old('featured_categories', $ws?->featured_categories ?? [])); @endphp
                        <label class="cw-cat-chip {{ $selected ? 'selected' : '' }}">
                            <input type="checkbox" name="featured_categories[]" value="{{ $cat }}" {{ $selected ? 'checked' : '' }}
                                onchange="this.closest('.cw-cat-chip').classList.toggle('selected', this.checked)">
                            {{ $cat }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ─── Save ─── --}}
    <div class="form-footer" style="margin-top:20px;">
        <button type="submit" class="btn-primary">{{ __('Save Website Settings') }}</button>
    </div>
</form>

{{-- ─── CMS Pages ─── --}}
<div class="cw-section" style="margin-top:36px;">
    <div class="section-label">{{ __('Pages') }}</div>
    <p style="font-size:13px;color:#64748b;margin:4px 0 0;">{{ __('Create pages like About Us, Terms & Conditions, Contact, etc. They appear in the catalog website navigation.') }}</p>

    {{-- Existing pages --}}
    @if(isset($catalogPages) && $catalogPages->isNotEmpty())
        @foreach($catalogPages as $cmsPage)
            <div class="cw-card" style="margin-top:12px;">
                <div class="cw-page-row">
                    <div>
                        <div class="cw-page-title">{{ $cmsPage->title }}</div>
                        <div class="cw-page-meta">
                            /page/{{ $cmsPage->slug }} &middot;
                            <span class="{{ $cmsPage->is_published ? 'cw-published' : 'cw-draft' }}">{{ $cmsPage->is_published ? __('Published') : __('Draft') }}</span>
                            &middot; {{ ucfirst($cmsPage->type) }}
                        </div>
                    </div>
                    <div class="cw-page-actions">
                        <button type="button" class="cw-btn-sm" onclick="toggleEditPage({{ $cmsPage->id }})">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            {{ __('Edit') }}
                        </button>
                        <form method="POST" action="{{ route('settings.catalog-pages.destroy', $cmsPage) }}" onsubmit="return confirm('{{ __('Delete this page?') }}')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="cw-btn-sm danger">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                {{ __('Delete') }}
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Edit form (hidden) --}}
                <div id="edit-page-{{ $cmsPage->id }}" style="display:none;" class="cw-edit-panel">
                    <form method="POST" action="{{ route('settings.catalog-pages.update', $cmsPage) }}">
                        @csrf
                        @method('PUT')
                        <div class="form-row" style="grid-template-columns:1fr;">
                            <div class="field">
                                <label class="field-label">{{ __('Title') }}</label>
                                <input type="text" name="title" value="{{ $cmsPage->title }}" class="field-input" required>
                            </div>
                        </div>
                        <div class="form-row" style="grid-template-columns:1fr;margin-top:12px;">
                            <div class="field">
                                <label class="field-label">{{ __('Content') }}</label>
                                <textarea name="content" class="field-input" rows="8">{{ $cmsPage->content }}</textarea>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer;">
                                <input type="hidden" name="is_published" value="0">
                                <input type="checkbox" name="is_published" value="1" {{ $cmsPage->is_published ? 'checked' : '' }}>
                                {{ __('Published') }}
                            </label>
                            <button type="submit" class="btn-primary" style="padding:7px 16px;font-size:12px;">{{ __('Update Page') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    @endif

    {{-- Add new page --}}
    <div class="cw-card dashed" style="margin-top:12px;">
        <button type="button" class="cw-add-btn" onclick="document.getElementById('cwNewPageForm').style.display = document.getElementById('cwNewPageForm').style.display === 'none' ? 'block' : 'none'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            {{ __('Add New Page') }}
        </button>
        <div id="cwNewPageForm" style="display:none;" class="cw-edit-panel">
            <form method="POST" action="{{ route('settings.catalog-pages.store') }}">
                @csrf
                <div class="form-row cols-2">
                    <div class="field">
                        <label class="field-label">{{ __('Page Title') }}</label>
                        <input type="text" name="title" class="field-input" required placeholder="{{ __('e.g. About Us') }}">
                    </div>
                    <div class="field">
                        <label class="field-label">{{ __('Page Type') }}</label>
                        <select name="type" class="field-input">
                            <option value="about">{{ __('About') }}</option>
                            <option value="terms">{{ __('Terms & Conditions') }}</option>
                            <option value="contact">{{ __('Contact') }}</option>
                            <option value="custom">{{ __('Custom') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-row" style="grid-template-columns:1fr;margin-top:12px;">
                    <div class="field">
                        <label class="field-label">{{ __('Content') }}</label>
                        <textarea name="content" class="field-input" rows="8" placeholder="{{ __('Write your page content here...') }}"></textarea>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                    <button type="submit" class="btn-primary" style="padding:7px 16px;font-size:12px;">{{ __('Create Page') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleEditPage(id) {
        const el = document.getElementById('edit-page-' + id);
        if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }

    // Sync color picker with text display
    const cwPicker = document.getElementById('cwColorPicker');
    const cwText   = document.getElementById('cwColorText');
    if (cwPicker && cwText) {
        cwPicker.addEventListener('input', () => { cwText.value = cwPicker.value; });
    }
</script>
