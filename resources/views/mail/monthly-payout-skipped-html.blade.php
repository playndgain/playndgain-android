<x-mail::message>
<x-slot:hero>
<x-mail::hero eyebrow="Payout" title="This month's payout was not requested" />
</x-slot:hero>
We could not open this month's withdrawal for publisher #{{ $pubId }}.

<x-mail::panel>
{{ $reason }}
</x-mail::panel>

Fix that on the Payments page and the next monthly run picks it up. The balance is yours either way.

<x-mail::button :url="\App\Support\Identity::url().'/dashboard/payments'">
Open Payments
</x-mail::button>
</x-mail::message>
