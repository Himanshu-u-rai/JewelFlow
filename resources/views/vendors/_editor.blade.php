<dialog id="vendor-editor" class="vendor-editor jf-scrollbar" x-ref="editor" aria-labelledby="vendor-editor-title"
        data-restore="{{ $restoreVendorEditor ? 'true' : 'false' }}"
        x-data="vendorEditor({{ \Illuminate\Support\Js::from(['restore' => (bool) $restoreVendorEditor, 'title' => $editorVendor ? 'Edit Vendor' : 'Add Vendor']) }})"
        @vendor-editor-open.window="open($event.detail.url, $event.detail.title, $event.detail.opener)"
        @cancel="if (submitting) $event.preventDefault()" @close="dismissed()"
        @turbo:before-cache.document="cleanup()" @turbo:before-render.document="cleanup()">
    <header class="vendor-editor-head">
        <h2 id="vendor-editor-title" x-text="title">{{ $editorVendor ? 'Edit Vendor' : 'Add Vendor' }}</h2>
        <button type="button" class="btn btn-secondary" aria-label="Close Vendor form" @click="close()" :disabled="submitting"><x-icon name="x" size="18" /></button>
    </header>
    <p class="vendor-editor-message" role="status" x-show="loading" x-cloak>Loading form…</p>
    <div class="vendor-editor-message" role="alert" x-show="error" x-cloak>
        <p x-text="error"></p>
        <a :href="url" class="btn btn-secondary">Open full page</a>
    </div>
    <div @turbo:frame-load="loaded($event)" @turbo:frame-missing="failed($event)" @turbo:fetch-request-error="failed($event)"
         @turbo:submit-start="submitting = true" @turbo:submit-end="submitting = false">
        @if($restoreVendorEditor)
            @include('vendors._editor-form', ['vendor' => $editorVendor])
        @else
            <turbo-frame id="vendor-editor-frame" x-ref="frame"></turbo-frame>
        @endif
    </div>
</dialog>
