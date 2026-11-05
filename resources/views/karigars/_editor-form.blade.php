<turbo-frame id="karigar-editor-frame" x-ref="frame">
    <form method="POST" action="{{ isset($karigar) ? route('karigars.update', $karigar) : route('karigars.store') }}" class="kf-form" data-turbo-frame="_top">
        @csrf
        @isset($karigar)<input type="hidden" name="_method" value="PUT">@endisset
        <input type="hidden" name="_karigar_editor" value="1">
        @if($errors->any())
            <div class="karigar-editor-errors" role="alert" tabindex="-1" data-editor-errors>
                <p>Please review these fields. Your entered values have been kept.</p>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @include('karigars._form')
        <footer class="kf-actions">
            <button type="submit" class="kf-submit" :disabled="submitting" x-text="submitting ? 'Saving…' : '{{ isset($karigar) ? 'Update Karigar' : 'Save Karigar' }}'">{{ isset($karigar) ? 'Update Karigar' : 'Save Karigar' }}</button>
            <button type="button" class="kf-cancel" @click="close()" :disabled="submitting">Cancel</button>
        </footer>
    </form>
</turbo-frame>
