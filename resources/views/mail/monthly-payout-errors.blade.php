Monthly payout — rules failed

The scheduled run could not open a withdrawal for {!! count($failures) !!} publisher(s).

@foreach ($failures as $failure)
- #{!! $failure['pub_id'] !!} — {!! $failure['reason'] !!}
@endforeach
