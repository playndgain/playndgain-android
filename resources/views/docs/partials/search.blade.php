{{-- Search modal. x-cloak, so a reader with JS off never sees a dialog they
     cannot open or close — the nav sidebar is their index instead. --}}
<div x-show="open" x-cloak class="fixed inset-0 z-[60]" role="dialog" aria-modal="true" aria-label="Search documentation">
  <div class="absolute inset-0 bg-ink-950/85 backdrop-blur-sm" @click="hide()"></div>

  <div class="relative mx-auto mt-[12vh] w-[min(560px,92vw)] overflow-hidden rounded-card border border-line-800 bg-ink-900 shadow-card">
    <div class="flex items-center gap-3 border-b border-line-800 px-4">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#7D8CA6" stroke-width="2" class="shrink-0" aria-hidden="true">
        <circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4" stroke-linecap="round"/>
      </svg>
      <input x-ref="input" x-model="query" @input="selected = 0"
             @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
             @keydown.enter.prevent="go()"
             type="text" autocomplete="off" spellcheck="false" placeholder="Search the documentation"
             class="w-full bg-transparent py-3.5 text-[14px] text-mist-50 placeholder:text-mist-400 focus:outline-none">
      <button type="button" @click="hide()" class="num shrink-0 text-[11px] text-mist-500 hover:text-mist-400">ESC</button>
    </div>

    <ul class="max-h-[52vh] overflow-y-auto p-2">
      <template x-for="(hit, i) in results" :key="hit.slug">
        <li>
          <a :href="'{{ url('/docs') }}/' + hit.slug" @mouseenter="selected = i"
             class="block rounded-control px-3 py-2.5"
             :class="selected === i ? 'bg-ink-850' : ''">
            <span class="num block text-[11px] uppercase tracking-wider text-mist-400" x-text="hit.section"></span>
            <span class="block text-[14px] font-medium text-mist-50" x-text="hit.title"></span>
            <span class="mt-0.5 block truncate text-[12.5px] text-mist-400" x-text="hit.excerpt"></span>
          </a>
        </li>
      </template>

      <li x-show="! query" class="px-3 py-7 text-center text-[13px] text-mist-400">
        Type to search. <span class="num">&uarr;&darr;</span> to move, <span class="num">&crarr;</span> to open.
      </li>
      <li x-show="query && index && ! results.length" class="px-3 py-7 text-center text-[13px] text-mist-400">
        Nothing matches &ldquo;<span class="text-mist-300" x-text="query"></span>&rdquo;.
      </li>
    </ul>
  </div>
</div>
