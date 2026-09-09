@props(['rows' => []])
@foreach ($rows as $label => $value)
{{ $label }}: {{ $value }}
@endforeach

