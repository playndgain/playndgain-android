@php
    $publisher = \App\Filament\Publisher\Support\CurrentPublisher::get();
    $status = $publisher?->status;
@endphp

{{-- Account state, said on every page rather than only on the one that
     refused something. Gold-tinted, never a warning yellow: attention is the
     brand's own colour here, and nothing on this surface is money direction. --}}
@if ($publisher === null)
    <div class="mx-auto max-w-7xl px-4 pt-4">
        <div class="fi-brand-banner">
            <strong>You are in the publisher panel as an admin.</strong>
            This login has no publisher account, so you cannot create apps here.
            Impersonate a publisher from the admin panel, or sign in as one.
        </div>
    </div>
@elseif ($status === \App\Enums\PublisherStatus::Pending)
    <div class="mx-auto max-w-7xl px-4 pt-4">
        <div class="fi-brand-banner">
            <strong>Your account is under review.</strong>
            You can create apps now — they go live once approved. Fire a test conversion in the meantime;
            the wall serves live offers only after a person approves you.
        </div>
    </div>
@elseif ($status === \App\Enums\PublisherStatus::Suspended)
    <div class="mx-auto max-w-7xl px-4 pt-4">
        <div class="fi-brand-banner">
            <strong>Your account is suspended.</strong>
            The panel is read-only and withdrawals are blocked.
            @if (filled($publisher?->notes))
                <span class="fi-brand-banner-muted">{{ \Illuminate\Support\Str::limit($publisher->notes, 300) }}</span>
            @endif
            <a href="mailto:{{ \App\Support\Identity::supportEmail() }}">Contact support</a>
        </div>
    </div>
@endif
