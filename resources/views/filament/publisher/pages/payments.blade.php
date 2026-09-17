<x-filament-panels::page>
    {{-- Where the next payout goes, stated once, above the tiles. A legacy
         destination of a type no longer offered still holds the flag until the
         publisher makes one they can see their default — docs/08 §10. --}}
    <div class="ad-payout-state">
        @if ($this->payoutState['ready'])
            <span class="ad-status ad-status--active">
                <span class="ad-status__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-check-badge" class="fi-icon" />
                </span>
                <span class="ad-status__label">Ready to be paid</span>
            </span>
            <p>
                Your next payout goes to <strong>{{ $this->payoutState['method'] }}</strong>.
                @if ($this->payoutState['legacy'])
                    That method is no longer offered. Set one up below and make it your default to switch.
                @endif
            </p>
        @elseif (! $this->payoutState['billing'])
            <span class="ad-status ad-status--pending">
                <span class="ad-status__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="fi-icon" />
                </span>
                <span class="ad-status__label">Billing details required</span>
            </span>
            <p>Your address goes on the credit note that comes with every payout. Nothing can be paid out, and no payment method can be set up, until this is complete.</p>

            <x-filament::button
                type="button"
                wire:click="openBilling"
                icon="heroicon-o-arrow-right"
                icon-position="after"
                class="ad-payout-state__cta"
            >
                Complete billing details
            </x-filament::button>
        @else
            <span class="ad-status ad-status--pending">
                <span class="ad-status__mark" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="fi-icon" />
                </span>
                <span class="ad-status__label">No payment method</span>
            </span>
            <p>Pick a method below and make it your default, or your cleared balance stays on the account.</p>
        @endif
    </div>

    {{-- Billing first, and until it is complete it is the only thing on this
         page that can be acted on: the payment method row is inert markup and
         openMethod() refuses server-side too — docs/08 §10. A suspended
         account opens both modals read-only — docs/08 §1. --}}
    <div class="ad-methods">
        <button
            type="button"
            wire:click="openBilling"
            class="ad-method {{ $this->billingRow['complete'] ? '' : 'ad-method--required' }}"
        >
            <span class="ad-method__icon" aria-hidden="true">
                <x-filament::icon icon="lucide-building-2" class="fi-icon" />
            </span>

            <span class="ad-method__body">
                <span class="ad-method__name">Billing details</span>
                <span class="ad-method__summary">{{ $this->billingRow['summary'] }}</span>
            </span>

            @if ($this->billingRow['complete'])
                <x-filament::icon icon="heroicon-o-chevron-right" class="ad-method__chevron fi-icon" />
            @else
                <span class="ad-status ad-status--pending">
                    <span class="ad-status__mark" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="fi-icon" />
                    </span>
                    <span class="ad-status__label">Required</span>
                </span>
            @endif
        </button>

        @if ($this->billingRow['complete'])
            <button
                type="button"
                wire:click="openMethod"
                class="ad-method {{ $this->methodRow['active'] ? 'ad-method--active' : '' }}"
            >
                <span class="ad-method__icon" aria-hidden="true">
                    <x-filament::icon icon="lucide-wallet" class="fi-icon" />
                </span>

                <span class="ad-method__body">
                    <span class="ad-method__name">Payment method</span>
                    <span class="ad-method__summary">{{ $this->methodRow['summary'] }}</span>
                </span>

                @if ($this->methodRow['active'])
                    <span class="ad-status ad-status--active">
                        <span class="ad-status__mark" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-check-badge" class="fi-icon" />
                        </span>
                        <span class="ad-status__label">Default</span>
                    </span>
                @elseif ($this->methodRow['saved'])
                    <span class="ad-status">
                        <span class="ad-status__label">Saved</span>
                    </span>
                @endif

                <x-filament::icon icon="heroicon-o-chevron-right" class="ad-method__chevron fi-icon" />
            </button>
        @else
            <div class="ad-method ad-method--locked" aria-disabled="true">
                <span class="ad-method__icon" aria-hidden="true">
                    <x-filament::icon icon="lucide-lock" class="fi-icon" />
                </span>

                <span class="ad-method__body">
                    <span class="ad-method__name">Payment method</span>
                    <span class="ad-method__summary">Complete your billing details first</span>
                </span>
            </div>
        @endif
    </div>

    <div class="ad-method-card ad-method-card--note">
        <h3>How you get paid</h3>
        <p>Earnings clear on the 1st of the following month. A payout for everything cleared is opened automatically that day and sent to whichever method is your default.</p>
        <p>The minimum payout applies to the whole cleared balance; a month below it is carried forward and paid together with the next one.</p>
        <p>Crypto is paid on exactly the chain set on that method. A wrong chain loses the funds.</p>
        <p>Your billing details are printed on the credit note that comes with every payout, so they have to be complete before a payment method can be set up.</p>
        <p>Changing or switching a method does not move a payout that is already requested. It keeps the destination it was requested with.</p>
    </div>

    <x-filament::section heading="Payment history" description="Months that had chargebacks. Earned, reversed, and what remains to be paid. More info opens the invoice.">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
