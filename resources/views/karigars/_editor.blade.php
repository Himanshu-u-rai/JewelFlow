<dialog id="karigar-editor" class="karigar-editor kf-shell" x-ref="editor" aria-labelledby="karigar-editor-title"
        data-restore="{{ $restoreEditor ? 'true' : 'false' }}"
        x-data="karigarEditor({{ \Illuminate\Support\Js::from(['restore' => (bool) $restoreEditor, 'title' => $editorKarigar ? 'Edit Karigar' : 'Add Karigar']) }})"
        @karigar-editor-open.window="open($event.detail.url, $event.detail.title, $event.detail.opener)"
        @cancel="if (submitting) $event.preventDefault()" @close="dismissed()"
        @turbo:before-cache.document="cleanup()" @turbo:before-render.document="cleanup()">
    <header class="karigar-editor-head">
        <h2 id="karigar-editor-title" x-text="title">{{ $editorKarigar ? 'Edit Karigar' : 'Add Karigar' }}</h2>
        <button type="button" class="btn btn-secondary" aria-label="Close Karigar form" @click="close()" :disabled="submitting"><x-icon name="x" size="18" /></button>
    </header>
    <p class="karigar-editor-message" role="status" x-show="loading" x-cloak>Loading form…</p>
    <div class="karigar-editor-message" role="alert" x-show="error" x-cloak>
        <p x-text="error"></p>
        <a :href="url" class="btn btn-secondary">Open full page</a>
    </div>
    <div @turbo:frame-load="loaded($event)" @turbo:frame-missing="failed($event)" @turbo:fetch-request-error="failed($event)"
         @turbo:submit-start="submitting = true" @turbo:submit-end="submitting = false">
        @if($restoreEditor)
            @include('karigars._editor-form', ['karigar' => $editorKarigar])
        @else
            <turbo-frame id="karigar-editor-frame" x-ref="frame"></turbo-frame>
        @endif
    </div>
</dialog>
