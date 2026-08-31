<?php

use App\Models\Setting;

/**
 * Shared arrangement for the alert suite.
 *
 * Pest only auto-loads `tests/Pest.php`, so each file in this directory
 * `require_once`s this one — same convention as tests/Feature/Money.
 */

/**
 * Writes the recipient list the way the Settings page writes it: a JSON array
 * under `alert_emails`. Setting::set clears the settings cache itself.
 *
 * @param  list<string>  $emails
 */
function alertRecipients(array $emails): void
{
    Setting::set('alert_emails', json_encode($emails));
}
