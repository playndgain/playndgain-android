{{-- The offerwall · trace event `convert`. Light band, so it carries
     `data-light`: the trace rail is dark-on-dark and fades out across it. --}}
<section id="wall" data-light x-data="{ theme: 'obsidian', styles: @js(\App\Support\LandingWall::themeStyles()) }" class="scroll-mt-24 bg-chalk py-24 text-[#0E1013] lg:py-32">
  <div class="mx-auto max-w-[1280px] px-6">
    <div class="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">

      <div>
        <p class="num mb-3 text-[12px] tracking-wider text-[#B37613]">YOUR SURFACE, NOT OURS</p>
        <h2 class="display text-[2rem] font-bold leading-tight lg:text-[2.5rem]">The wall wears your brand</h2>
        <p class="mt-4 max-w-[50ch] text-[16px] leading-relaxed text-[#4A525E]">
          Colours, radius, currency name and rate are per placement. Your users never
          see a logo that isn't yours. Pick a palette and watch it apply — this is the
          same control your dashboard gives you.
        </p>

        <div class="mt-8">
          <p class="mb-3 text-[12px] font-medium uppercase tracking-wider text-[#6E7784]">Try a theme</p>
          <div class="flex flex-wrap gap-2.5">
            <button @click="theme='obsidian'" :class="theme==='obsidian' ? 'ring-2 ring-offset-2 ring-[#0E1013]' : ''" class="flex items-center gap-2 rounded-lg border border-[#D6DAE1] bg-white px-3.5 py-2 text-[13px] font-medium transition-all">
              <span class="size-3.5 rounded-full bg-[#08090A]"></span> Obsidian
            </button>
            <button @click="theme='daylight'" :class="theme==='daylight' ? 'ring-2 ring-offset-2 ring-[#0E1013]' : ''" class="flex items-center gap-2 rounded-lg border border-[#D6DAE1] bg-white px-3.5 py-2 text-[13px] font-medium transition-all">
              <span class="size-3.5 rounded-full bg-[#F1F3F7]"></span> Daylight
            </button>
            <button @click="theme='forest'" :class="theme==='forest' ? 'ring-2 ring-offset-2 ring-[#0E1013]' : ''" class="flex items-center gap-2 rounded-lg border border-[#D6DAE1] bg-white px-3.5 py-2 text-[13px] font-medium transition-all">
              <span class="size-3.5 rounded-full bg-[#0D2B22]"></span> Forest
            </button>
            <button @click="theme='violet'" :class="theme==='violet' ? 'ring-2 ring-offset-2 ring-[#0E1013]' : ''" class="flex items-center gap-2 rounded-lg border border-[#D6DAE1] bg-white px-3.5 py-2 text-[13px] font-medium transition-all">
              <span class="size-3.5 rounded-full bg-[#2A1F4D]"></span> Violet
            </button>
          </div>
        </div>

        <dl class="mt-10 grid gap-6 sm:grid-cols-3">
          <div><dt class="num text-[20px] font-medium">{{ number_format($stats['countries']) }}</dt><dd class="mt-1 text-[13px] leading-snug text-[#4A525E]">countries targeted per offer</dd></div>
          <div><dt class="num text-[20px] font-medium">{{ $stats['fraud_rules'] }}</dt><dd class="mt-1 text-[13px] leading-snug text-[#4A525E]">fraud rules before redirect</dd></div>
          <div><dt class="num text-[20px] font-medium">0</dt><dd class="mt-1 text-[13px] leading-snug text-[#4A525E]">lines of JavaScript to write</dd></div>
        </dl>
      </div>

      <div class="flex justify-center">
        <div class="w-[340px]">
          @include('partials.landing.wall-frame', ['bindTheme' => true])
        </div>
      </div>

    </div>
  </div>
</section>
