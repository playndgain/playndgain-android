@extends('layouts.site')

@section('title', 'Contact — '.\App\Support\Identity::name())
@section('description', 'Publisher applications, integration problems, payout questions, supplying offers, and data requests. One form, answered by a person.')

@section('content')
{{-- A plain form POST to the same origin — no fetch, no CORS, no third-party
     form service. Everything below works with the bundle blocked; there is no
     Alpine on this page at all.

     Errors are amber, not `--loss`. Red on an AdVault surface means negative
     money and nothing else (docs/09 §1), and a red field border on a contact
     form is exactly the decorative use that rule exists to prevent.

     Fields that carry a permanent hint describe themselves through a wrapper
     that is always in the document, so the error simply appears inside it. A
     conditional `aria-describedby` pointing at an id that only exists on the
     error path is the version that fails an audit the first time it is edited. --}}
<main id="main" class="mx-auto max-w-[1000px] px-6 pt-28 pb-24">

  <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_260px] lg:items-start lg:gap-14">

    <div class="min-w-0 max-w-[62ch]">
      <h1 class="display text-[2.1rem] font-bold leading-tight tracking-tight text-chalk">Contact</h1>
      <p class="mt-3 text-[16px] leading-relaxed text-steel-300">
        A person reads every one of these. Tell us which app or network you are asking about and we can usually
        answer in the first reply.
      </p>

      @if (session('status'))
        <p role="status"
           class="mt-8 rounded-[10px] border border-amber-500/30 bg-amber-950/40 px-4 py-3 text-[14px] leading-relaxed text-chalk">
          {{ session('status') }}
        </p>
      @endif

      <form method="POST" action="{{ route('contact.send') }}" class="mt-8 space-y-6">
        @csrf

        <div>
          <label for="name" class="mb-2 block text-[13.5px] font-medium text-chalk">Your name</label>
          <input id="name" name="name" type="text" value="{{ old('name') }}"
                 required maxlength="120" autocomplete="name"
                 @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                 class="w-full rounded-md border {{ $errors->has('name') ? 'border-amber-500/60' : 'border-steel-700' }} bg-obsidian-950 px-3 py-2.5 text-[15px] text-chalk focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">
          @error('name')
            <p id="name-error" class="mt-2 text-[13px] text-amber-400">{{ $message }}</p>
          @enderror
        </div>

        <div>
          <label for="email" class="mb-2 block text-[13.5px] font-medium text-chalk">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}"
                 required maxlength="190" autocomplete="email" aria-describedby="email-help"
                 @error('email') aria-invalid="true" @enderror
                 class="w-full rounded-md border {{ $errors->has('email') ? 'border-amber-500/60' : 'border-steel-700' }} bg-obsidian-950 px-3 py-2.5 text-[15px] text-chalk focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">
          <div id="email-help">
            @error('email')
              <p class="mt-2 text-[13px] text-amber-400">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-[13px] text-steel-300">We reply to this address and do nothing else with it.</p>
          </div>
        </div>

        <div>
          <label for="topic" class="mb-2 block text-[13.5px] font-medium text-chalk">What is it about?</label>
          <select id="topic" name="topic" required
                  @error('topic') aria-invalid="true" aria-describedby="topic-error" @enderror
                  class="w-full rounded-md border {{ $errors->has('topic') ? 'border-amber-500/60' : 'border-steel-700' }} bg-obsidian-950 px-3 py-2.5 text-[15px] text-chalk focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">
            <option value="">Choose one</option>
            @foreach ($topics as $value => $label)
              <option value="{{ $value }}" @selected(old('topic', $selected) === $value)>{{ $label }}</option>
            @endforeach
          </select>
          @error('topic')
            <p id="topic-error" class="mt-2 text-[13px] text-amber-400">{{ $message }}</p>
          @enderror
        </div>

        <div>
          <label for="message" class="mb-2 block text-[13.5px] font-medium text-chalk">Message</label>
          <textarea id="message" name="message" rows="8"
                    required minlength="20" maxlength="5000" aria-describedby="message-help"
                    @error('message') aria-invalid="true" @enderror
                    class="w-full rounded-md border {{ $errors->has('message') ? 'border-amber-500/60' : 'border-steel-700' }} bg-obsidian-950 px-3 py-2.5 text-[15px] leading-relaxed text-chalk focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">{{ old('message') }}</textarea>
          <div id="message-help">
            @error('message')
              <p class="mt-2 text-[13px] text-amber-400">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-[13px] text-steel-300">
              App ID, network name, or a transaction ID if you have one — it saves a round trip.
            </p>
          </div>
        </div>

        <button type="submit"
                class="rounded-lg bg-amber-500 px-5 py-2.5 text-[14px] font-semibold text-obsidian-950 transition-colors hover:bg-amber-400">
          Send message
        </button>
      </form>
    </div>

    <aside class="mt-14 lg:mt-0" aria-labelledby="faster">
      <h2 id="faster" class="mb-4 text-[13px] font-semibold text-chalk">Faster than waiting for us</h2>
      <ul class="space-y-3 text-[13.5px] leading-relaxed text-steel-300">
        <li>
          <a href="{{ url('/docs/quick-start') }}" class="text-amber-500 transition-colors hover:text-amber-400">Quick start</a>
          — live in five steps, no account call required.
        </li>
        <li>
          <a href="{{ url('/docs/troubleshooting') }}" class="text-amber-500 transition-colors hover:text-amber-400">Troubleshooting</a>
          — symptom, cause, fix. Most integration questions end here.
        </li>
        <li>
          <a href="{{ url('/legal/publisher-agreement') }}" class="text-amber-500 transition-colors hover:text-amber-400">Publisher agreement</a>
          — revenue share, the hold, the minimum, reversals.
        </li>
        <li>
          End-user access and deletion requests are endpoints, not tickets:
          <a href="{{ url('/legal/privacy') }}" class="text-amber-500 transition-colors hover:text-amber-400">how they work</a>.
        </li>
      </ul>
    </aside>
  </div>
</main>
@endsection
