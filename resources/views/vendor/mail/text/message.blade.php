<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="\App\Support\Identity::url()">
{{ \App\Support\Identity::name() }}
</x-mail::header>
</x-slot:header>

{{-- Hero, when a mail passes one: eyebrow and headline as a text line. --}}
@isset($hero)
{{ $hero }}
@endisset

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ \App\Support\Identity::name() }}. @lang('All rights reserved.')
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
