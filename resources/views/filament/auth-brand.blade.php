{{-- The brand panel beside every auth form — the landing hero, condensed:
     the mark, the claim, three proofs, and the real wall on a phone. It reads
     the panel it is rendered in, so the admin login does not promise payouts
     to the people who pay them. Decorative, hence aria-hidden; the form is
     the page.

     The proofs are capabilities, never a rate. What a publisher earns is
     their own commercial term, settled on their account and not quoted at
     a stranger on a login screen. --}}
@php
    $admin = filament()->getId() === 'admin';
@endphp
<aside class="fi-auth-brand" aria-hidden="true">
  <div class="fi-auth-brand-mark">
    <x-brand-mark :size="28" />
  </div>

  <div class="fi-auth-brand-body">
    @if ($admin)
      <p class="fi-auth-eyebrow">Operations</p>
      <p class="fi-auth-claim">Every click, rule and cent, in one place.</p>
      <p class="fi-auth-lede">Inventory, fraud verdicts, the ledger and payouts — the other side of the wall.</p>
      <ul class="fi-auth-proofs">
        <li>Append-only ledger</li>
        <li>Rules that explain themselves</li>
        <li>Signed postbacks</li>
      </ul>
    @else
      <p class="fi-auth-eyebrow">Rewarded monetisation for apps and games</p>
      <p class="fi-auth-claim">Turn playtime into payouts.</p>
      <p class="fi-auth-lede">Players complete offers inside your app. You are paid for every verified action — signed, on a ledger that is never edited, paid monthly.</p>
      <ul class="fi-auth-proofs">
        <li>Live in a day</li>
        <li>Your brand on the wall</li>
        <li>Monthly payouts</li>
      </ul>
    @endif
  </div>

  <div class="fi-auth-shot">
    <div class="fi-auth-phone">
      <img src="/img/landing/wall-dark.webp" alt="" width="780" height="1560" loading="lazy" decoding="async">
    </div>
    <div class="fi-auth-pill"><i>✓</i><span>+640 Coins<small>credited in 0.4 s</small></span></div>
  </div>
</aside>
