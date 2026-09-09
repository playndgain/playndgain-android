@extends('layouts.site')

@section('title', 'Unsubscribed — '.\App\Support\Identity::name())
@section('description', 'You have been taken off the mailing list.')

@php
    // A page reached from a mail client, once, by someone leaving. Nothing to
    // index and nothing to follow.
@endphp
@push('head')
  <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
<main id="main" class="mx-auto max-w-[1000px] px-6 pt-28 pb-24">
  <div class="max-w-[62ch]">
    <h1 class="display-2 text-[2.1rem] text-mist-50">You are off the list</h1>

    <p class="mt-4 text-[16px] leading-relaxed text-mist-300">
      We will not send release notes to <span class="text-mist-50">{{ $email }}</span> again.
      Your account mail is separate and keeps arriving: payout statements, credit notes, anything about money.
    </p>

    <p class="mt-4 text-[16px] leading-relaxed text-mist-300">
      Clicked this by mistake? The form in the footer puts you back on.
    </p>

    <div class="mt-8 flex flex-wrap gap-3">
      <a href="{{ url('/') }}" class="btn btn-primary btn-sm">Back to the site</a>
      <a href="{{ route('contact') }}" class="btn btn-ghost-ink btn-sm">Contact us</a>
    </div>
  </div>
</main>
@endsection
