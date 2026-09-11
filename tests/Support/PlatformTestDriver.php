<?php

namespace Tests\Support;

use App\Enums\ConnectorCategory;
use App\Networks\Connector;
use App\Networks\Drivers\FakeNetworkDriver;

/**
 * A platform connector that exists only for the tests.
 *
 * Phase one ships the identity layer before the connectors that need it, so
 * there is no registered Platform driver yet and nothing would exercise the two
 * behaviours that only a platform has: several `networks` rows on one driver,
 * and a driver select that puts its group first. This is the smallest thing
 * that does — the test double with different metadata and a different key.
 */
class PlatformTestDriver extends FakeNetworkDriver
{
    public static function key(): string
    {
        return 'platform-test';
    }

    /**
     * A platform appends the account's own shared secret to the URL it hands
     * over, because the macro template is the same for every tenant and the
     * secret is not. That literal is what the admin display has to mask.
     */
    public function postbackUrl(): string
    {
        return parent::postbackUrl().'?secret=super-secret&token=super-token&click_id={click_id}';
    }

    public static function connector(): Connector
    {
        return new Connector(
            'Platform test',
            ConnectorCategory::Platform,
            supportsMultipleAccounts: true,
            rejectedAfterCreditReverses: true,
        );
    }
}
