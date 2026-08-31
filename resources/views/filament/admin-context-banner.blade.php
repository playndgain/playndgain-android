@php
    use App\Filament\Publisher\Support\CurrentPublisher;

    $user = filament()->auth()->user();
    $showing = $user?->isAdmin() && CurrentPublisher::get() === null;
@endphp

{{--
    An admin in the publisher panel is looking at an empty one, and empty tables
    with no explanation read as a broken product rather than as an answer. The
    banner names why there is nothing here and points at the thing that does
    show real data — impersonation, which is audited.
--}}
@if ($showing)
    <div class="fi-admin-context-banner">
        <div>
            <p class="fi-admin-context-banner-title">You are viewing the publisher panel as an administrator.</p>
            <p class="fi-admin-context-banner-body">
                Nothing is listed because this panel only ever shows one publisher's data and your account is not one.
                To see a publisher's real view, impersonate them from Publishers — every switch is recorded.
            </p>
        </div>

        <a href="/admin/publishers" class="fi-admin-context-banner-action">Go to Publishers</a>
    </div>
@endif
