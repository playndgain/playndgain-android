{{-- Icon sprite. Glyphs only — the gradient tile lives on the container.
     Ten inline symbols instead of an icon library: docs/09 §8 budget.

     Shared by the landing site and the offerwall. `wall-sprite` carries the
     same `position: absolute` the utility does — wall.css is scoped to the
     wall's own views, so Tailwind never emits `absolute` into that bundle, and
     an inline zero-size <svg> without it still opens a line box and drops 24px
     of blank space into the top of the frame. --}}
<svg width="0" height="0" class="absolute wall-sprite" aria-hidden="true" focusable="false"><defs>
  <symbol id="ic-coin" viewBox="0 0 24 24">
    <path d="M12 2.6c5.2 0 9.4 2.1 9.4 4.7v9.4c0 2.6-4.2 4.7-9.4 4.7s-9.4-2.1-9.4-4.7V7.3C2.6 4.7 6.8 2.6 12 2.6Zm0 2c-4.3 0-7.4 1.6-7.4 2.7S7.7 10 12 10s7.4-1.6 7.4-2.7S16.3 4.6 12 4.6Zm7.4 5.6c-1.8 1.1-4.5 1.8-7.4 1.8s-5.6-.7-7.4-1.8v2.1c0 1.1 3.1 2.7 7.4 2.7s7.4-1.6 7.4-2.7v-2.1Zm0 4.9c-1.8 1.1-4.5 1.8-7.4 1.8s-5.6-.7-7.4-1.8v1.6c0 1.1 3.1 2.7 7.4 2.7s7.4-1.6 7.4-2.7v-1.6Z"/>
  </symbol>
  <symbol id="ic-chat" viewBox="0 0 24 24">
    <path d="M12 3c5 0 9 3.3 9 7.4 0 4.1-4 7.4-9 7.4-.9 0-1.8-.1-2.6-.3l-4.6 2.3a.6.6 0 0 1-.9-.6l.5-3.6C2.3 14.9 3 12.8 3 10.4 3 6.3 7 3 12 3Zm-3.4 6a1.4 1.4 0 1 0 0 2.8 1.4 1.4 0 0 0 0-2.8Zm3.4 0a1.4 1.4 0 1 0 0 2.8 1.4 1.4 0 0 0 0-2.8Zm3.4 0a1.4 1.4 0 1 0 0 2.8 1.4 1.4 0 0 0 0-2.8Z"/>
  </symbol>
  <symbol id="ic-bag" viewBox="0 0 24 24">
    <path d="M8.2 7V6.3a3.8 3.8 0 0 1 7.6 0V7h2.5c.7 0 1.3.5 1.4 1.2l1.1 10.3a2 2 0 0 1-2 2.2H5.2a2 2 0 0 1-2-2.2L4.3 8.2A1.4 1.4 0 0 1 5.7 7h2.5Zm2-.7V7h3.6v-.7a1.8 1.8 0 0 0-3.6 0ZM8.2 9H6.1l-1 9.4a.3.3 0 0 0 .3.3h13.2a.3.3 0 0 0 .3-.3L17.9 9h-2.1v2a1 1 0 0 1-2 0V9h-3.6v2a1 1 0 0 1-2 0V9Z"/>
  </symbol>
  <symbol id="ic-survey" viewBox="0 0 24 24">
    <path d="M8.5 2.6h7c.6 0 1 .5 1 1v.6h1.6c1.1 0 2 .9 2 2v14a2 2 0 0 1-2 2H5.9a2 2 0 0 1-2-2v-14c0-1.1.9-2 2-2h1.6v-.6c0-.5.4-1 1-1Zm-1 4.6H5.9v14h12.2v-14h-1.6v.6c0 .6-.4 1-1 1h-7c-.6 0-1-.4-1-1v-.6Zm2 3.9 1.5 1.5 3-3 1.4 1.4-4.4 4.4L8 12.5l1.5-1.4Zm-1.5 7.4h8v1.8h-8v-1.8Z"/>
  </symbol>
  <symbol id="ic-game" viewBox="0 0 24 24">
    <path d="M7.6 5.8h8.8a5.6 5.6 0 0 1 5.5 4.6l.9 5a3.4 3.4 0 0 1-6.3 2.3l-.9-1.5H8.4l-.9 1.5a3.4 3.4 0 0 1-6.3-2.3l.9-5a5.6 5.6 0 0 1 5.5-4.6Zm0 2a3.6 3.6 0 0 0-3.5 3l-.9 4.9a1.4 1.4 0 0 0 2.6.9l1.5-2.4h9.4l1.5 2.4a1.4 1.4 0 0 0 2.6-.9l-.9-5a3.6 3.6 0 0 0-3.5-2.9H7.6Zm.4 2.4h1.8v1.5h1.5v1.8H9.8v1.5H8v-1.5H6.5v-1.8H8v-1.5Zm7.3.3a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4Zm2.2 2.6a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4Z"/>
  </symbol>
  {{-- The offerwall's card facts: which devices an offer runs on, and how many
       people have started it. Referenced with <use>, so a hundred cards cost a
       forty-byte reference each rather than a hundred copies of the path. --}}
  <symbol id="ic-android" viewBox="0 0 24 24">
    <path d="M6.8 5.6 5.6 3.5a.6.6 0 0 1 1-.6l1.3 2.2a7.7 7.7 0 0 1 6.2 0l1.3-2.2a.6.6 0 1 1 1 .6l-1.2 2.1A6.6 6.6 0 0 1 18.6 11H5.4a6.6 6.6 0 0 1 1.4-5.4ZM9 8.2a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm6 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6ZM5.4 12.4h13.2v6.3a1.8 1.8 0 0 1-1.8 1.8h-.5v1.6a1.5 1.5 0 0 1-3 0v-1.6h-2.6v1.6a1.5 1.5 0 0 1-3 0v-1.6h-.5a1.8 1.8 0 0 1-1.8-1.8v-6.3ZM2.9 12.2a1.5 1.5 0 0 1 1.5 1.5v3.6a1.5 1.5 0 0 1-3 0v-3.6a1.5 1.5 0 0 1 1.5-1.5Zm18.2 0a1.5 1.5 0 0 1 1.5 1.5v3.6a1.5 1.5 0 0 1-3 0v-3.6a1.5 1.5 0 0 1 1.5-1.5Z"/>
  </symbol>
  <symbol id="ic-ios" viewBox="0 0 24 24">
    <path d="M16.1 12.6c0-2 1.6-3 1.7-3.1a3.8 3.8 0 0 0-3-1.6c-1.3-.1-2.5.7-3.1.7-.6 0-1.6-.7-2.7-.7a4 4 0 0 0-3.4 2c-1.4 2.5-.4 6.2 1 8.2.7 1 1.5 2.1 2.6 2.1 1 0 1.4-.7 2.7-.7 1.2 0 1.6.7 2.7.6 1.1 0 1.8-1 2.5-2a8.9 8.9 0 0 0 1.1-2.3c0-.1-2.1-.9-2.1-3.2ZM14 6.3c.6-.7 1-1.7.9-2.7-.9 0-2 .6-2.6 1.3-.5.6-1 1.7-.9 2.6 1 .1 2-.5 2.6-1.2Z"/>
  </symbol>
  <symbol id="ic-desktop" viewBox="0 0 24 24">
    <path d="M4 3.6h16a2 2 0 0 1 2 2v9.6a2 2 0 0 1-2 2h-6.2v2.4h3.1a1 1 0 0 1 0 2H7.1a1 1 0 0 1 0-2h3.1v-2.4H4a2 2 0 0 1-2-2V5.6a2 2 0 0 1 2-2Zm0 2v9.6h16V5.6H4Z"/>
  </symbol>
  <symbol id="ic-person" viewBox="0 0 24 24">
    <path d="M12 2.8a4.4 4.4 0 1 1 0 8.8 4.4 4.4 0 0 1 0-8.8Zm0 2a2.4 2.4 0 1 0 0 4.8 2.4 2.4 0 0 0 0-4.8ZM12 13c4 0 7.2 2.2 7.2 5v2a1 1 0 0 1-1 1H5.8a1 1 0 0 1-1-1v-2c0-2.8 3.2-5 7.2-5Zm0 2c-3.1 0-5.2 1.6-5.2 3v1h10.4v-1c0-1.4-2.1-3-5.2-3Z"/>
  </symbol>
  <symbol id="ic-video" viewBox="0 0 24 24">
    <path d="M4.4 4.2h15.2a2.4 2.4 0 0 1 2.4 2.4v10.8a2.4 2.4 0 0 1-2.4 2.4H4.4A2.4 2.4 0 0 1 2 17.4V6.6a2.4 2.4 0 0 1 2.4-2.4Zm0 2a.4.4 0 0 0-.4.4v10.8c0 .2.2.4.4.4h15.2c.2 0 .4-.2.4-.4V6.6a.4.4 0 0 0-.4-.4H4.4Zm5.5 2.3 6 3.5-6 3.5v-7Z"/>
  </symbol>
  {{-- The wall's app chrome: top bar, menu sheet and the mobile app-nav. --}}
  <symbol id="ic-menu" viewBox="0 0 24 24">
    <path d="M4 6.2h16a1.2 1.2 0 0 0 0-2.4H4a1.2 1.2 0 0 0 0 2.4Zm16 5H4a1.2 1.2 0 0 0 0 2.4h16a1.2 1.2 0 0 0 0-2.4Zm0 7H4a1.2 1.2 0 0 0 0 2.4h16a1.2 1.2 0 0 0 0-2.4Z"/>
  </symbol>
  <symbol id="ic-spark" viewBox="0 0 24 24">
    <path d="M12 2.2 13.6 9.2 20.8 12 13.6 14.8 12 21.8 10.4 14.8 3.2 12 10.4 9.2 12 2.2Z"/>
  </symbol>
  <symbol id="ic-home" viewBox="0 0 24 24">
    <path d="M12 2.4a2 2 0 0 0-1.2.4l-8 6.5A2 2 0 0 0 4 12.7V20a2 2 0 0 0 2 2h3.5a1 1 0 0 0 1-1v-4.5h3V21a1 1 0 0 0 1 1H18a2 2 0 0 0 2-2v-7.3a2 2 0 0 0 1.2-3.4l-8-6.5a2 2 0 0 0-1.2-.4Z"/>
  </symbol>
  <symbol id="ic-clock" viewBox="0 0 24 24">
    <path d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm-1 3a1 1 0 0 1 1 1v3.6l2.8 1.6a1 1 0 0 1-1 1.8l-3.5-2a1 1 0 0 1-.5-.9V8a1 1 0 0 1 1-1Z"/>
  </symbol>
  <symbol id="ic-grid" viewBox="0 0 24 24">
    <path d="M4 3h6.5A1.5 1.5 0 0 1 12 4.5v6.5a1.5 1.5 0 0 1-1.5 1.5H4A1.5 1.5 0 0 1 2.5 11V4.5A1.5 1.5 0 0 1 4 3Zm10 0h6a1.5 1.5 0 0 1 1.5 1.5V11a1.5 1.5 0 0 1-1.5 1.5h-6a1.5 1.5 0 0 1-1.5-1.5V4.5A1.5 1.5 0 0 1 14 3ZM4 12.5h6.5a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5H4A1.5 1.5 0 0 1 2.5 20v-6A1.5 1.5 0 0 1 4 12.5Zm10 0h6a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5h-6a1.5 1.5 0 0 1-1.5-1.5v-6a1.5 1.5 0 0 1 1.5-1.5Z"/>
  </symbol>
  <symbol id="ic-chevron" viewBox="0 0 24 24">
    <path d="M9.2 4.8 16.4 12 9.2 19.2 7.6 17.6 13.2 12 7.6 6.4 9.2 4.8Z"/>
  </symbol>
  <symbol id="ic-sun" viewBox="0 0 24 24">
    <path d="M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0-5.5a1 1 0 0 1 1 1V4a1 1 0 0 1-2 0V2.5a1 1 0 0 1 1-1Zm0 18.5a1 1 0 0 1 1 1v1.5a1 1 0 0 1-2 0V21a1 1 0 0 1 1-1ZM2.5 11a1 1 0 0 1 0 2H1a1 1 0 0 1 0-2h1.5Zm20 0a1 1 0 0 1 0 2H21a1 1 0 0 1 0-2h1.5ZM4.9 3.5l1.4 1.4A1 1 0 0 1 4.9 6.3L3.5 4.9a1 1 0 0 1 1.4-1.4Zm14.2 14.2 1.4 1.4a1 1 0 0 1-1.4 1.4l-1.4-1.4a1 1 0 0 1 1.4-1.4Zm1.4-14.2a1 1 0 0 1 0 1.4L19.1 6.3a1 1 0 0 1-1.4-1.4l1.4-1.4a1 1 0 0 1 1.4 0ZM6.3 17.7a1 1 0 0 1 0 1.4l-1.4 1.4a1 1 0 0 1-1.4-1.4l1.4-1.4a1 1 0 0 1 1.4 0Z"/>
  </symbol>
  <symbol id="ic-moon" viewBox="0 0 24 24">
    <path d="M20.9 14.5A9 9 0 0 1 9.5 3.1a1 1 0 0 0-1.2-1.2A10 10 0 1 0 22 15.7a1 1 0 0 0-1.1-1.2Z"/>
  </symbol>
  <symbol id="ic-shield" viewBox="0 0 24 24">
    <path d="M12 2a2 2 0 0 0-.9.2l-7 2.7A2 2 0 0 0 2.8 6.7v5.1c0 5 3.4 8.6 8.3 10a2 2 0 0 0 1.8 0c4.9-1.4 8.3-5 8.3-10V6.7a2 2 0 0 0-1.3-1.8l-7-2.7A2 2 0 0 0 12 2Zm3.6 7.1-4.2 4.7a1 1 0 0 1-1.5 0L8.4 12a1 1 0 1 1 1.5-1.4l1 1.1 3.4-3.8a1 1 0 0 1 1.3 1.2Z"/>
  </symbol>
  <symbol id="ic-doc" viewBox="0 0 24 24">
    <path d="M6 2h8.4a2 2 0 0 1 1.4.6l3.6 3.6a2 2 0 0 1 .6 1.4V20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm7.5 2.5V8h3.5l-3.5-3.5ZM8 12a1 1 0 0 0 0 2h8a1 1 0 0 0 0-2H8Zm0 4a1 1 0 0 0 0 2h5a1 1 0 0 0 0-2H8Z"/>
  </symbol>
</defs></svg>
