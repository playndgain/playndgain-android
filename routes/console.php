<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks  →  docs/11-infrastructure.md §4
|--------------------------------------------------------------------------
|
| Owned by the orchestrator. Commands are registered here as phases land them;
| the frequency column in the spec is the contract.
|
*/

// Catalogue pulls go on the `sync` queue, never `postbacks` — a ten-minute
// sync must never sit in front of a conversion postback.
Schedule::command('networks:sync')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('ledger:reconcile')->dailyAt('03:00');

// Statements are documents derived from the ledger; this proves each one still
// agrees with the withdrawal it points at — docs/06 §9. Reports, never fixes.
Schedule::command('payouts:reconcile-statements')->dailyAt('03:05');

// The other half of the same question. `ledger:reconcile` proves a balance
// equals its entries; this proves the entries are the right amount — a
// conversion credited at the wrong rev-share reconciles against its own wrong
// entry all night. Runs after it so a run that fails names both.
Schedule::command('money:audit')->dailyAt('03:15');

// Rebuilt hourly for today and once nightly for yesterday, because postbacks
// arrive late — docs/06 §7.
Schedule::command('stats:aggregate --today')->hourly()->withoutOverlapping();
Schedule::command('stats:aggregate --yesterday')->dailyAt('00:30');

// Off-peak: the IP2Proxy pull is several hundred megabytes and IP2Location
// rate-limits the free tier to five downloads a day.
Schedule::command('geoip:update')->weeklyOn(1, '04:30')->withoutOverlapping();

// Retention — docs/11 §5. Off-peak and after the nightly aggregate, so a click
// is only ever pruned once its numbers are already in `daily_stats`.
Schedule::command('logs:prune')->dailyAt('04:00')->withoutOverlapping();

// `conversions:clear` from the spec's schedule table is deliberately absent.
// Clearing is a comparison, not a state change: `ledger_entries.available_at`
// carries the hold deadline and Publisher::clearedBalance() reads it against
// `now()`. An hourly job flipping a status column would be a second source of
// truth for whether money is spendable, and the two would eventually disagree.

// docs/11 §6. The clean runs first so the night's backup is never the one
// deleted to make room, and `backup:monitor` is what makes the admin health
// page's "last backup" row a check rather than a decoration.
Schedule::command('backup:clean')->dailyAt('01:30');
Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:monitor')->dailyAt('06:00');

// Thresholds from docs/04 §6 and docs/11 §7. Fifteen minutes because that is
// the window the postback failure rate is measured over — each run looks at
// roughly the period since the last one.
// Previous calendar month's statement is written, then its cleared earnings
// are requested on the default payment method. Publishers who fail the payout
// rules get an error mail rather than a silent skip — see
// MonthlyWithdrawalService. One command, so the order is guaranteed.
Schedule::command('withdrawals:request-monthly')->monthlyOn(1, '00:10')->withoutOverlapping();

Schedule::command('alerts:check')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
