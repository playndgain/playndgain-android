<?php

namespace App\Fraud\Rules\Concerns;

use App\Enums\ConversionStatus;
use App\Fraud\FraudContext;
use App\Models\Conversion;
use Illuminate\Database\Eloquent\Builder;

/**
 * The base query every cap and baseline rule counts against.
 *
 * Two exclusions, both load-bearing:
 *
 * - Test-mode rows never trip a real cap. A publisher proving their
 *   integration works must not spend the app's daily allowance doing it.
 * - Rejected and reversed rows do not count either. Letting a fraud rejection
 *   consume the cap turns one bad postback into a self-inflicted outage for
 *   every honest user on the app.
 */
trait CountsConversions
{
    /**
     * @return Builder<Conversion>
     */
    protected function countable(FraudContext $context): Builder
    {
        $query = Conversion::query()
            ->withoutGlobalScopes()
            ->where('is_test', false)
            ->whereIn('status', [ConversionStatus::Approved, ConversionStatus::Pending]);

        // The conversion being judged must not count itself.
        $current = $context->conversion?->getKey();

        return $current === null ? $query : $query->whereKeyNot($current);
    }
}
