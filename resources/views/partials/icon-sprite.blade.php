{{-- Icon sprite. Twenty inline symbols instead of an icon library.

     Shared by the offerwall and the publisher's live preview. The UI glyphs
     are 1.6px strokes in the current colour — thin, monochrome, quiet — and
     the three platform marks stay filled, because a stroked Apple logo is not
     the Apple logo. `wall-sprite` carries the same `position: absolute` the
     utility does — wall.css is scoped to the wall's own views, so Tailwind
     never emits `absolute` into that bundle, and an inline zero-size <svg>
     without it still opens a line box and drops 24px of blank space into the
     top of the frame. --}}
<svg width="0" height="0" class="absolute wall-sprite" aria-hidden="true" focusable="false"><defs>
  <symbol id="ic-coin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="8.2"/><circle cx="12" cy="12" r="3.4"/>
  </symbol>
  <symbol id="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4.5 6.5A2.5 2.5 0 0 1 7 4h10a2.5 2.5 0 0 1 2.5 2.5v7A2.5 2.5 0 0 1 17 16h-6l-4.5 3.5V16H7a2.5 2.5 0 0 1-2.5-2.5v-7Z"/>
  </symbol>
  <symbol id="ic-bag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M6 8.5h12l-.9 10.2a1.5 1.5 0 0 1-1.5 1.3H8.4a1.5 1.5 0 0 1-1.5-1.3L6 8.5Z"/><path d="M9 8.5V7a3 3 0 0 1 6 0v1.5"/>
  </symbol>
  <symbol id="ic-survey" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <rect x="6" y="5" width="12" height="15.5" rx="1.5"/><path d="M9.5 5V3.8h5V5M9.2 12.2l1.8 1.8 3.4-3.6M9.5 17h5"/>
  </symbol>
  <symbol id="ic-game" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M7.2 7h9.6a4.4 4.4 0 0 1 4.3 3.5l.8 4.6a2.6 2.6 0 0 1-4.7 1.9l-1-1.6H7.8l-1 1.6a2.6 2.6 0 0 1-4.7-1.9l.8-4.6A4.4 4.4 0 0 1 7.2 7Z"/><path d="M8.6 10.2v3.2M7 11.8h3.2M15 11.2h.01M17 13h.01"/>
  </symbol>
  {{-- The offerwall's card facts: which devices an offer runs on, and how many
       people have started it. Referenced with <use>, so a hundred rows cost a
       forty-byte reference each rather than a hundred copies of the path. --}}
  <symbol id="ic-android" viewBox="0 0 24 24">
    <path d="M6.8 5.6 5.6 3.5a.6.6 0 0 1 1-.6l1.3 2.2a7.7 7.7 0 0 1 6.2 0l1.3-2.2a.6.6 0 1 1 1 .6l-1.2 2.1A6.6 6.6 0 0 1 18.6 11H5.4a6.6 6.6 0 0 1 1.4-5.4ZM9 8.2a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm6 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6ZM5.4 12.4h13.2v6.3a1.8 1.8 0 0 1-1.8 1.8h-.5v1.6a1.5 1.5 0 0 1-3 0v-1.6h-2.6v1.6a1.5 1.5 0 0 1-3 0v-1.6h-.5a1.8 1.8 0 0 1-1.8-1.8v-6.3ZM2.9 12.2a1.5 1.5 0 0 1 1.5 1.5v3.6a1.5 1.5 0 0 1-3 0v-3.6a1.5 1.5 0 0 1 1.5-1.5Zm18.2 0a1.5 1.5 0 0 1 1.5 1.5v3.6a1.5 1.5 0 0 1-3 0v-3.6a1.5 1.5 0 0 1 1.5-1.5Z"/>
  </symbol>
  <symbol id="ic-ios" viewBox="0 0 24 24">
    <path d="M16.1 12.6c0-2 1.6-3 1.7-3.1a3.8 3.8 0 0 0-3-1.6c-1.3-.1-2.5.7-3.1.7-.6 0-1.6-.7-2.7-.7a4 4 0 0 0-3.4 2c-1.4 2.5-.4 6.2 1 8.2.7 1 1.5 2.1 2.6 2.1 1 0 1.4-.7 2.7-.7 1.2 0 1.6.7 2.7.6 1.1 0 1.8-1 2.5-2a8.9 8.9 0 0 0 1.1-2.3c0-.1-2.1-.9-2.1-3.2ZM14 6.3c.6-.7 1-1.7.9-2.7-.9 0-2 .6-2.6 1.3-.5.6-1 1.7-.9 2.6 1 .1 2-.5 2.6-1.2Z"/>
  </symbol>
  <symbol id="ic-desktop" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <rect x="3" y="4.5" width="18" height="12" rx="1.6"/><path d="M9.5 20h5M12 16.5V20"/>
  </symbol>
  <symbol id="ic-person" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="8" r="3.6"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>
  </symbol>
  <symbol id="ic-video" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m10.5 9.5 4 2.5-4 2.5z"/>
  </symbol>
  {{-- The wall's app chrome: top bar, menu sheet and the mobile app-nav. --}}
  <symbol id="ic-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 7h16M4 12h16M4 17h16"/>
  </symbol>
  <symbol id="ic-spark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 3.5 13.8 10 20.5 12 13.8 14 12 20.5 10.2 14 3.5 12 10.2 10 12 3.5Z"/>
  </symbol>
  <symbol id="ic-home" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4.5 10.5 12 4.5l7.5 6V19a1.5 1.5 0 0 1-1.5 1.5h-3.8V14.5h-4.4v6H6A1.5 1.5 0 0 1 4.5 19v-8.5Z"/>
  </symbol>
  <symbol id="ic-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="8.2"/><path d="M12 7.5V12l3 1.8"/>
  </symbol>
  <symbol id="ic-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1"/>
  </symbol>
  <symbol id="ic-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="m9 5 7 7-7 7"/>
  </symbol>
  <symbol id="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="4"/><path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8"/>
  </symbol>
  <symbol id="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/>
  </symbol>
  <symbol id="ic-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 3.5 5 6v5.5c0 4.4 2.9 7.6 7 9 4.1-1.4 7-4.6 7-9V6l-7-2.5Z"/><path d="m9 12 2 2 4-4.5"/>
  </symbol>
  <symbol id="ic-doc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M7 3.5h7l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9.5A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5Z"/><path d="M14 3.5V8h4M8.5 12.5h7M8.5 16h4.5"/>
  </symbol>
</defs></svg>
