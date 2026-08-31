{{-- Auth lives in the publisher panel: /dashboard/login and /dashboard/register
     (App\Filament\Publisher\Auth\Register). Hard-coded rather than route(): a
     renamed panel path should break a link, not throw a 500 on the homepage.

     Section links are absolute (`/#how`, not `#how`). This header renders on the
     docs, the legal pages and the contact form as well, and a bare fragment
     there resolves against the current path and lands nowhere. --}}
<header x-data="{ solid: false, open: false }"
        @scroll.window="solid = window.scrollY > 24"
        class="fixed inset-x-0 top-0 z-50 transition-all duration-200"
        :class="solid ? 'bg-obsidian-950/85 backdrop-blur-xl border-b border-steel-700 shadow-[0_1px_24px_rgba(0,0,0,.6)]' : 'border-b border-transparent'">
  <nav class="mx-auto flex max-w-[1280px] items-center gap-8 px-6 py-4" aria-label="Main">
    <a href="{{ url('/') }}" class="shrink-0">
      <x-brand-mark :size="26" />
    </a>

    <ul class="ml-4 hidden items-center gap-7 text-[14px] text-steel-300 lg:flex">
      <li><a href="{{ url('/#how') }}" class="transition-colors hover:text-chalk">How it works</a></li>
      <li><a href="{{ url('/#wall') }}" class="transition-colors hover:text-chalk">Offerwall</a></li>
      <li><a href="{{ url('/#terms') }}" class="transition-colors hover:text-chalk">Payouts</a></li>
      <li><a href="{{ url('/#integrate') }}" class="transition-colors hover:text-chalk">Integration</a></li>
      <li><a href="/docs/introduction" class="transition-colors hover:text-chalk">Docs</a></li>
      <li><a href="{{ route('advertise') }}" class="transition-colors hover:text-chalk">Advertise</a></li>
    </ul>

    <div class="ml-auto flex items-center gap-3">
      <a href="/dashboard/login" class="hidden text-[14px] text-steel-300 transition-colors hover:text-chalk sm:block">Sign in</a>
      <a href="/dashboard/register" class="rounded-lg bg-amber-500 px-4 py-2 text-[14px] font-semibold text-obsidian-950 transition-colors hover:bg-amber-400">Start earning</a>
      <button @click="open = !open" class="lg:hidden p-1.5 -mr-1.5" :aria-expanded="open" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path x-show="!open" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"/>
          <path x-show="open" x-cloak d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/>
        </svg>
      </button>
    </div>
  </nav>

  <div x-show="open" x-cloak x-collapse class="border-t border-steel-700 bg-obsidian-950 lg:hidden">
    <ul class="mx-auto max-w-[1280px] space-y-1 px-6 py-4 text-[15px]">
      <li><a href="{{ url('/#how') }}"       @click="open=false" class="block py-2 text-steel-300">How it works</a></li>
      <li><a href="{{ url('/#wall') }}"      @click="open=false" class="block py-2 text-steel-300">Offerwall</a></li>
      <li><a href="{{ url('/#terms') }}"     @click="open=false" class="block py-2 text-steel-300">Payouts</a></li>
      <li><a href="{{ url('/#integrate') }}" @click="open=false" class="block py-2 text-steel-300">Integration</a></li>
      <li><a href="/docs/introduction" @click="open=false" class="block py-2 text-steel-300">Docs</a></li>
      <li><a href="{{ route('advertise') }}" @click="open=false" class="block py-2 text-steel-300">Advertise</a></li>
    </ul>
  </div>
</header>
