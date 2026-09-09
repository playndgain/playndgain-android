<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="\App\Support\Identity::url()">
{{ \App\Support\Identity::name() }}
</x-mail::header>
</x-slot:header>

{{-- Hero: the ink band under the mark, when a mail passes one. --}}
@isset($hero)
<x-slot:hero>
{!! $hero !!}
</x-slot:hero>
@endisset

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ \App\Support\Identity::name() }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
