<x-mail::message>
# Monthly payout could not be requested

We could not open this month's withdrawal for publisher #{{ $pubId }}.

<x-mail::panel>
{{ $reason }}
</x-mail::panel>

Fix that in the dashboard and the next run will pick it up. You can also request a withdrawal by hand once the rules pass.
</x-mail::message>
