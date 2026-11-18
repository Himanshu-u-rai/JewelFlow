<dialog class="settings-unsaved-dialog" data-settings-unsaved-dialog aria-labelledby="settings-unsaved-title" aria-describedby="settings-unsaved-description">
    <h2 id="settings-unsaved-title">{{ __('You have unsaved changes') }}</h2>
    <p id="settings-unsaved-description">{{ __('Save your changes before leaving, or discard them to continue. Nothing will be saved automatically.') }}</p>
    <div class="settings-unsaved-actions">
        <button type="button" class="btn btn-secondary" data-unsaved-discard>{{ __('Discard & leave') }}</button>
        <button type="button" class="btn btn-primary" data-unsaved-keep autofocus>{{ __('Keep editing') }}</button>
    </div>
</dialog>
