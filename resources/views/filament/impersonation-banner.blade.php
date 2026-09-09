@if (session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
    {{-- The one banner that must be impossible to miss: solid gold, ink text. --}}
    <div class="fi-impersonation-banner">
        <span>
            You are signed in as {{ filament()->auth()->user()?->name }}. Everything you do here is recorded against this publisher.
        </span>

        <a href="{{ route('impersonate.stop') }}">Stop impersonating</a>
    </div>
@endif
