<?php

namespace App\Console\Commands;

use App\Services\Money\MonthlyWithdrawalService;
use Illuminate\Console\Command;

/**
 * Writes last month's statements, then opens one withdrawal request per
 * eligible publisher. Failures are emailed, not retried here — the next
 * month's run is the retry.
 */
class RequestMonthlyWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawals:request-monthly';

    protected $description = 'Write last month\'s payout statements and request a withdrawal for every eligible publisher.';

    public function handle(MonthlyWithdrawalService $monthly): int
    {
        $result = $monthly->requestDue();

        $this->info("Statements {$result['statements']}. Requested {$result['requested']}. Skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
