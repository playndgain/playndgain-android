@if (session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
    <div class="flex flex-wrap items-center justify-center gap-3 bg-warning-500 px-4 py-2 text-center text-sm font-medium text-gray-950">
        <span>
            You are signed in as {{ filament()->auth()->user()?->name }}. Everything you do here is recorded against this publisher.
        </span>

        <a href="{{ route('impersonate.stop') }}"
           class="rounded-md bg-gray-950/15 px-2 py-1 underline underline-offset-2 hover:bg-gray-950/25">
            Stop impersonating
        </a>
    </div>
@endif
