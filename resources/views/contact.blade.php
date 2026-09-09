@extends('layouts.site')

@section('title', 'Contact — '.\App\Support\Identity::name())
@section('description', 'Publisher applications, integration problems, payout questions, supplying offers, and data requests. One form, answered by a person.')

@section('content')
{{-- A plain form POST to the same origin — no fetch, no CORS, no third-party
     form service. Everything below works with the bundle blocked; there is no
     Alpine on this page at all.

          money and nothing else (docs/09 §1), and a red field border on a contact
     form is exactly the decorative use that rule exists to prevent.

     Fields that carry a permanent hint describe themselves through a wrapper
     that is always in the document, so the error simply appears inside it. A
     conditional `aria-describedby` pointing at an id that only exists on the
     error path is the version that fails an audit the first time it is edited. --}}
<main id="main" class="mx-auto max-w-[1000px] px-6 pt-28 pb-24">

  <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_260px] lg:items-start lg:gap-14">

    <div class="min-w-0 max-w-[62ch]">
      <h1 class="display-2 text-[2.1rem] text-mist-50">Contact</h1>
      <p class="mt-3 text-[16px] leading-relaxed text-mist-300">
        A person reads every one of these. Tell us which app or network you are asking about and we can usually
        answer in the first reply.
      </p>

      @if (session('status'))
        <p role="status"
           class="mt-8 rounded-sm border border-gold-400/30 bg-gold-950 px-4 py-3 text-[14px] leading-relaxed text-mist-50">
          {{ session('status') }}
        </p>
      @elseif (session('delivery_failed'))
        {{-- The transport was down. role="alert" rather than status: this one
             costs the sender something if they miss it, and everything they
             typed is still in the fields below via `old()`. --}}
        <p role="alert"
           class="mt-8 rounded-sm border border-gold-400/30 bg-gold-950 px-4 py-3 text-[14px] leading-relaxed text-mist-50">
          {{ session('delivery_failed') }}
        </p>
      @endif

      <form method="POST" action="{{ route('contact.send') }}" class="mt-8 space-y-6">
        @csrf

        <div>
          <label for="name" class="mb-2 block text-[13.5px] font-medium text-mist-50">Your name</label>
          <input id="name" name="name" type="text" value="{{ old('name') }}"
                 required maxlength="120" autocomplete="name"
                 @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                 class="w-full rounded-sm border {{ $errors->has('name') ? 'border-gold-400/60' : 'border-line-800' }} bg-ink-875 px-3 py-2.5 text-[15px] text-mist-50">
          @error('name')
            <p id="name-error" class="mt-2 text-[13px] text-gold-400">{{ $message }}</p>
          @enderror
        </div>

        <div>
          <label for="email" class="mb-2 block text-[13.5px] font-medium text-mist-50">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}"
                 required maxlength="190" autocomplete="email" aria-describedby="email-help"
                 @error('email') aria-invalid="true" @enderror
                 class="w-full rounded-sm border {{ $errors->has('email') ? 'border-gold-400/60' : 'border-line-800' }} bg-ink-875 px-3 py-2.5 text-[15px] text-mist-50">
          <div id="email-help">
            @error('email')
              <p class="mt-2 text-[13px] text-gold-400">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-[13px] text-mist-300">We reply to this address and do nothing else with it.</p>
          </div>
        </div>

        <div>
          <label for="topic" class="mb-2 block text-[13.5px] font-medium text-mist-50">What is it about?</label>
          <select id="topic" name="topic" required
                  @error('topic') aria-invalid="true" aria-describedby="topic-error" @enderror
                  class="w-full rounded-sm border {{ $errors->has('topic') ? 'border-gold-400/60' : 'border-line-800' }} bg-ink-875 px-3 py-2.5 text-[15px] text-mist-50">
            <option value="">Choose one</option>
            @foreach ($topics as $value => $label)
              <option value="{{ $value }}" @selected(old('topic', $selected) === $value)>{{ $label }}</option>
            @endforeach
          </select>
          @error('topic')
            <p id="topic-error" class="mt-2 text-[13px] text-gold-400">{{ $message }}</p>
          @enderror
        </div>

        <div>
          <label for="message" class="mb-2 block text-[13.5px] font-medium text-mist-50">Message</label>
          <textarea id="message" name="message" rows="8"
                    required minlength="20" maxlength="5000" aria-describedby="message-help"
                    @error('message') aria-invalid="true" @enderror
                    class="w-full rounded-sm border {{ $errors->has('message') ? 'border-gold-400/60' : 'border-line-800' }} bg-ink-875 px-3 py-2.5 text-[15px] leading-relaxed text-mist-50">{{ old('message') }}</textarea>
          <div id="message-help">
            @error('message')
              <p class="mt-2 text-[13px] text-gold-400">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-[13px] text-mist-300">
              App ID, network name, or a transaction ID if you have one — it saves a round trip.
            </p>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Send message</button>
      </form>
    </div>

    <aside class="mt-14 lg:mt-0" aria-labelledby="faster">
      <h2 id="faster" class="mb-4 text-[13px] font-semibold text-mist-50">Faster than waiting for us</h2>
      <ul class="space-y-3 text-[13.5px] leading-relaxed text-mist-300">
        <li>
          <a href="{{ url('/docs/quick-start') }}" class="text-gold-400 transition-colors hover:text-gold-300">Quick start</a>
          — live in five steps, no account call required.
        </li>
        <li>
          <a href="{{ url('/docs/troubleshooting') }}" class="text-gold-400 transition-colors hover:text-gold-300">Troubleshooting</a>
          — symptom, cause, fix. Most integration questions end here.
        </li>
        <li>
          <a href="{{ url('/legal/publisher-agreement') }}" class="text-gold-400 transition-colors hover:text-gold-300">Publisher agreement</a>
          — what you are paid, the hold, the minimum, reversals.
        </li>
        <li>
          End-user access and deletion requests are endpoints, not tickets:
          <a href="{{ url('/legal/privacy') }}" class="text-gold-400 transition-colors hover:text-gold-300">how they work</a>.
        </li>
      </ul>
    </aside>
  </div>
</main>
@endsection
