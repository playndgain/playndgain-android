<x-mail::message>
<x-slot:hero>
<x-mail::hero eyebrow="Ops" title="{{ count($failures) }} publisher(s) failed the payout rules" />
</x-slot:hero>
The scheduled run could not open a withdrawal for these publishers. Each one has been told why.

<x-mail::facts :rows="collect($failures)->mapWithKeys(fn (array $f): array => ['#'.$f['pub_id'] => $f['reason']])->all()" />
</x-mail::message>
