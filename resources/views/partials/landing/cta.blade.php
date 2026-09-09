@php
    $support = \App\Support\Identity::supportEmail();

    // A dotted globe, front hemisphere only, drawn from arithmetic rather than
    // an image: eight-degree steps of latitude and longitude on a sphere,
    // projected orthographically, fading with depth.
    $dots = [];
    for ($lat = -80; $lat <= 80; $lat += 8) {
        for ($lon = -90; $lon <= 90; $lon += 8) {
            $z = cos(deg2rad($lat)) * cos(deg2rad($lon));
            $dots[] = [
                round(300 + 260 * cos(deg2rad($lat)) * sin(deg2rad($lon)), 1),
                round(300 - 260 * sin(deg2rad($lat)), 1),
                round(0.18 + 0.62 * $z, 2),
            ];
        }
    }
@endphp

{{-- The close: one full-width ink card on paper, a glow that drifts very
     slowly, the mark, and the two things to do next. --}}
<section class="on-paper pb-[var(--section)]" aria-labelledby="cta-title">
  <div class="shell">
    <div class="hero-bg card-lg relative overflow-hidden px-7 py-14 text-fog-50 sm:px-12 lg:grid lg:grid-cols-[1.1fr_1fr] lg:items-center lg:px-20 lg:py-20" data-reveal>
      <div class="cta-orb -left-[10%] -bottom-[40%] h-[120%] w-[70%]" aria-hidden="true"></div>
      <svg class="cta-dots pointer-events-none absolute -right-24 -top-16 h-[620px] w-[620px] lg:-right-10 lg:top-1/2 lg:-translate-y-1/2" viewBox="0 0 600 600" aria-hidden="true">
        @foreach ($dots as [$x, $y, $o])
          <circle cx="{{ $x }}" cy="{{ $y }}" r="3.2" fill="#FFB020" opacity="{{ $o }}"/>
        @endforeach
      </svg>

      <div class="relative">
        <x-brand-mark :size="44" :wordmark="false" />
        <h2 id="cta-title" class="display-2 mt-7 max-w-[18ch]">Start earning from the players you already have.</h2>
        <p class="lead mt-5 max-w-[48ch] text-fog-300">Create a placement, fire a test conversion end to end, and watch the first signed postback arrive — before anyone reviews your account.</p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a href="/dashboard/register" class="btn btn-primary">Start earning</a>
          <a href="{{ url('/docs/quick-start') }}" class="btn btn-ghost-ink">Read the docs</a>
        </div>
        <p class="mt-6 text-[14px] text-fog-500">Or write to <a href="mailto:{{ $support }}" class="font-medium text-fog-300 underline underline-offset-4 transition-colors duration-[var(--motion-fast)] hover:text-fog-50">{{ $support }}</a></p>
      </div>
    </div>
  </div>
</section>
