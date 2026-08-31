{{-- The trace, promoted to page furniture: a hairline whose playhead is scrubbed
     by document scroll. aria-hidden because every event on it is already in the
     document flow below — to a screen reader it is duplicate text.
     2xl only; below that there is no margin to put it in. --}}
<div id="rail" class="pointer-events-none fixed left-6 top-1/2 z-30 hidden -translate-y-1/2 transition-opacity duration-300 2xl:block" aria-hidden="true">
  <div class="relative flex flex-col gap-7">
    <div class="absolute left-[3.5px] top-1 bottom-1 w-px bg-steel-700"></div>
    <div id="railFill" class="absolute left-[3.5px] top-1 w-px bg-amber-500 origin-top" style="height:0"></div>
    <div class="rail-dot relative flex items-center gap-3"><span class="z-10 size-2 rounded-full bg-steel-600"></span><span class="num text-[10px] text-steel-400 opacity-0 transition-opacity">07.221 click</span></div>
    <div class="rail-dot relative flex items-center gap-3"><span class="z-10 size-2 rounded-full bg-steel-600"></span><span class="num text-[10px] text-steel-400 opacity-0 transition-opacity">07.229 redirect</span></div>
    <div class="rail-dot relative flex items-center gap-3"><span class="z-10 size-2 rounded-full bg-steel-600"></span><span class="num text-[10px] text-steel-400 opacity-0 transition-opacity">51.006 convert</span></div>
    <div class="rail-dot relative flex items-center gap-3"><span class="z-10 size-2 rounded-full bg-steel-600"></span><span class="num text-[10px] text-steel-400 opacity-0 transition-opacity">51.014 ledger</span></div>
    <div class="rail-dot relative flex items-center gap-3"><span class="z-10 size-2 rounded-full bg-steel-600"></span><span class="num text-[10px] text-steel-400 opacity-0 transition-opacity">51.109 postback</span></div>
    <div class="rail-dot relative flex items-center gap-3"><span class="z-10 size-2 rounded-full bg-steel-600"></span><span class="num text-[10px] text-steel-400 opacity-0 transition-opacity">+30d  payout</span></div>
  </div>
</div>
