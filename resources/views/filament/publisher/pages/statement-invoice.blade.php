<x-filament-panels::page>
    <div class="mb-5">
        <a href="{{ $this->paymentsUrl() }}" class="text-sm text-gray-400 hover:text-gray-200">
            ← Payment history
        </a>
    </div>

    <div class="ad-invoice">
        <div class="ad-invoice__summary">
            <div>
                <p class="ad-invoice__kicker">Period</p>
                <p class="ad-invoice__value">{{ $this->statement->periodLabel() }}</p>
            </div>
            <div>
                <p class="ad-invoice__kicker">Earned</p>
                <p class="ad-invoice__value ad-invoice__value--num">{{ $this->dollars($this->statement->gross) }}</p>
            </div>
            <div>
                <p class="ad-invoice__kicker">Chargebacks</p>
                <p class="ad-invoice__value ad-invoice__value--num ad-invoice__value--loss">{{ $this->dollars($this->statement->chargebacks) }}</p>
            </div>
            <div>
                <p class="ad-invoice__kicker">Actual payment</p>
                <p class="ad-invoice__value ad-invoice__value--num">{{ $this->dollars($this->statement->net) }}</p>
            </div>
            <div>
                <p class="ad-invoice__kicker">Due</p>
                <p class="ad-invoice__value">{{ $this->statement->due_date?->format('M j, Y') }}</p>
            </div>
        </div>

        @if ($this->offers() === [])
            <p class="ad-invoice__empty">No chargebacks on this statement.</p>
        @else
            <div class="ad-invoice__scroll">
                <table class="ad-invoice__table">
                    <thead>
                        <tr>
                            <th>App</th>
                            <th>Offer</th>
                            <th class="ad-invoice__num">Reversals</th>
                            <th class="ad-invoice__num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->offers() as $offer)
                            <tr>
                                <td class="ad-invoice__app">{{ $offer['app'] }}</td>
                                <td class="ad-invoice__offer">{{ $offer['offer'] }}</td>
                                <td class="ad-invoice__num">{{ $offer['count'] }}</td>
                                <td class="ad-invoice__num ad-invoice__value--loss">{{ $this->dollars($offer['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total</td>
                            <td class="ad-invoice__num">{{ $this->reversalCount() }}</td>
                            <td class="ad-invoice__num ad-invoice__value--loss">{{ $this->dollars($this->statement->chargebacks) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
