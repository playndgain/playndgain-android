{{-- The selector row of the dashboard header when the publisher has no apps
     yet — see Dashboard::filtersForm(). $url is null when there is no
     publisher account to create one for (an admin looking in). --}}
<div class="pg-dash-noapps">
    <span class="pg-dash-noapps__label">Viewing</span>

    @if ($url)
        <a href="{{ $url }}" class="pg-dash-noapps__cta">
            <span aria-hidden="true">📱</span>
            Add your first app
            <span aria-hidden="true">&rarr;</span>
        </a>

        <span class="pg-dash-noapps__hint">Per-app figures appear here once an app exists.</span>
    @else
        <span class="pg-dash-noapps__hint">No apps to choose from — this login has no publisher account.</span>
    @endif
</div>
