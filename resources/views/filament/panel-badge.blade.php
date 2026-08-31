{{--
    Which panel am I in? Two dark dashboards on one domain, sharing a brand and
    a layout, are easy to confuse — and the admin one can move money and ban
    accounts. The badge is always visible, never dismissible, and carries the
    admin accent so the answer is readable at arm's length rather than by
    reading the URL.
--}}
@php($isAdmin = $panel === 'admin')

<div @class([
        'fi-panel-badge',
        'fi-panel-badge-admin' => $isAdmin,
    ])
     role="status">
    <span class="fi-panel-badge-dot" aria-hidden="true"></span>
    {{ $isAdmin ? 'Admin' : 'Publisher' }}
</div>
