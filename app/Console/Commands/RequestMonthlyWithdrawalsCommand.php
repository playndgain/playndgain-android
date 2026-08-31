<?php

namespace App\Console\Commands;

use App\Services\Money\MonthlyWithdrawalService;
use Illuminate\Console\Command;

/**
 * Opens one withdrawal request per eligible publisher. Failures are emailed,
 * not retried here — the next month's run is the retry.
 */
class RequestMonthlyWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:request-monthly';

    protected $description = 'Request a monthly withdrawal for every eligible publisher.';

    public function handle(MonthlyWithdrawalService $monthly): int
    {
        $result = $monthly->requestDue();

        $this->info("Requested {$result['requested']}. Skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
