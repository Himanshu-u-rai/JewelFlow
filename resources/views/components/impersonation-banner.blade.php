@if(!empty($impersonationBanner))
    <div class="impersonation-banner">
        <div class="impersonation-banner__content">
            <div>
                <strong>Support Mode:</strong>
                You are currently impersonating {{ $impersonationBanner['shop_name'] }} ({{ $impersonationBanner['user_name'] }}).
                @if(!empty($impersonationBanner['expires_at']))
                    <span class="impersonation-banner__expires">Ends {{ $impersonationBanner['expires_at']->format('h:i A') }}</span>
                @endif
            </div>
        </div>
        <form method="POST" action="{{ route('admin.impersonation.stop') }}">
            @csrf
            <button type="submit" class="impersonation-banner__button">Exit Impersonation</button>
        </form>
    </div>
@endif
