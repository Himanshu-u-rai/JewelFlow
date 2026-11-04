@php
    $restoreEditor = $errors->any() && old('_reorder_editor') === '1';
    $oldRuleId = filter_var(old('_reorder_rule_id'), FILTER_VALIDATE_INT) ?: null;
    $initialRule = [
        'id' => $oldRuleId ?? '',
        'action' => $oldRuleId ? route('reorder.update', $oldRuleId) : route('reorder.store'),
        'category' => old('category', '') ?? '',
        'sub_category' => old('sub_category', '') ?? '',
        'min_stock_threshold' => old('min_stock_threshold', ''),
        'vendor_id' => old('vendor_id', '') ?? '',
        'is_active' => (bool) old('is_active', true),
    ];
@endphp
<dialog id="reorder-rule-dialog" class="reorder-rule-dialog" x-ref="ruleDialog" aria-labelledby="reorder-rule-title"
        data-reopen="{{ $restoreEditor ? 'true' : 'false' }}"
        x-data="reorderRuleEditor({{ \Illuminate\Support\Js::from(['createUrl' => route('reorder.store'), 'subcategories' => $subCategoryMap, 'categories' => $categories->pluck('name')->values(), 'vendorIds' => $vendors->pluck('id')->map(fn ($id) => (string) $id)->values(), 'hasErrors' => $restoreEditor, 'initialRule' => $initialRule, 'errors' => $errors->keys()]) }})"
        @reorder-rule-open.window="open($event.detail || null)"
        @cancel="if (submitting) $event.preventDefault()"
        @turbo:submit-end.document="submitting = false"
        @turbo:before-cache.document="cleanup()" @turbo:before-render.document="cleanup()">
    <header class="reorder-editor-head">
        <h2 id="reorder-rule-title" x-text="editing ? 'Edit reorder rule' : 'Add reorder rule'">{{ $oldRuleId ? 'Edit reorder rule' : 'Add reorder rule' }}</h2>
        <button type="button" class="btn btn-secondary" aria-label="Close reorder rule" @click="close()" :disabled="submitting"><x-icon name="x" size="18" /></button>
    </header>
    <form method="POST" action="{{ $initialRule['action'] }}" :action="form.action" data-reorder-rule-form data-turbo-frame="_top" @submit="submitting = true">
        @csrf
        <input type="hidden" name="_method" value="PUT" :disabled="!editing">
        <input type="hidden" name="_reorder_editor" value="1">
        <input type="hidden" name="_reorder_rule_id" :value="form.id">
        @if($restoreEditor)
            <div class="reorder-editor-errors" role="alert" x-show="showErrors">
                <p>Please review the highlighted fields.</p>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div class="reorder-editor-grid">
            <div>
                <label for="reorder-editor-category">Category</label>
                <select id="reorder-editor-category" name="category" x-model="form.category" @change="form.sub_category = ''" :aria-invalid="showErrors && errors.includes('category') ? 'true' : 'false'">
                    <option value="">All categories</option>
                    <template x-if="form.category && !categories.includes(form.category)"><option :value="form.category" :selected="true" x-text="form.category + ' (unavailable)' "></option></template>
                    @foreach($categories as $category)<option value="{{ $category->name }}">{{ $category->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="reorder-editor-sub-category">Sub-category</label>
                <select id="reorder-editor-sub-category" name="sub_category" x-model="form.sub_category" :aria-invalid="showErrors && errors.includes('sub_category') ? 'true' : 'false'">
                    <option value="">All sub-categories</option>
                    <template x-if="form.sub_category && !subCategories.includes(form.sub_category)"><option :value="form.sub_category" :selected="true" x-text="form.sub_category + ' (unavailable)' "></option></template>
                    <template x-for="name in subCategories" :key="name"><option :value="name" :selected="name === form.sub_category" x-text="name"></option></template>
                </select>
            </div>
            <div>
                <label for="reorder-editor-minimum">Minimum stock threshold <span aria-hidden="true">*</span></label>
                <input id="reorder-editor-minimum" name="min_stock_threshold" type="number" min="1" step="1" required
                       value="{{ $initialRule['min_stock_threshold'] }}" x-model="form.min_stock_threshold" :aria-invalid="showErrors && errors.includes('min_stock_threshold') ? 'true' : 'false'">
            </div>
            <div>
                <label for="reorder-editor-vendor">Preferred vendor</label>
                <select id="reorder-editor-vendor" name="vendor_id" x-model="form.vendor_id" :aria-invalid="showErrors && errors.includes('vendor_id') ? 'true' : 'false'">
                    <option value="">None</option>
                    <template x-if="form.vendor_id && !vendorIds.includes(String(form.vendor_id))"><option :value="form.vendor_id" :selected="true">Unavailable vendor — choose another or None</option></template>
                    @foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->name }}</option>@endforeach
                </select>
            </div>
        </div>
        <label class="reorder-editor-active" x-show="editing">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" x-model="form.is_active"> Active rule
        </label>
        <p class="reorder-editor-hint">A rule monitors matching in-stock items. It does not place a purchase order.</p>
        <footer class="reorder-editor-actions">
            <button type="button" class="btn btn-secondary" @click="close()" :disabled="submitting">Cancel</button>
            <button type="submit" class="btn btn-primary" :disabled="submitting" x-text="submitting ? 'Saving…' : (editing ? 'Update rule' : 'Create rule')">Save rule</button>
        </footer>
    </form>
</dialog>
