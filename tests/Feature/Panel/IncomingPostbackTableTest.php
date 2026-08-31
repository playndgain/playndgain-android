<?php

use App\Enums\IncomingPostbackResult;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\Config;

it('reads the first filled query key for each payload field', function () {
    $log = new IncomingPostback([
        'query' => [
            'payout' => '0.04',
            'user_payout' => '0.02',
            'click_id' => '4e02319d9889b5ab9dcd338a30a6cab1',
            'user_id' => 'B22917863673',
            'event_name' => 'Default',
            'campaign_id' => '2619244',
        ],
    ]);

    expect($log->queryValue('payout', 'payout_usd'))->toBe('0.04')
        ->and($log->queryValue('user_payout', 'user_payout_in_vc'))->toBe('0.02')
        ->and($log->queryValue('click_id', 'aff_sub1'))->toBe('4e02319d9889b5ab9dcd338a30a6cab1')
        ->and($log->queryValue('missing', 'also_missing'))->toBeNull();
});

function incomingPostbackRow(): IncomingPostback
{
    $network = Network::factory()->create(['name' => 'MyChips', 'driver' => 'mychips-table']);

    return IncomingPostback::create([
        'network_id' => $network->id,
        'driver' => $network->driver,
        'method' => 'GET',
        'query' => [
            'click_id' => '4e02319d9889b5ab9dcd338a30a6cab1',
            'aff_sub1' => '',
            'payout' => '0.04',
            'user_payout' => '0.02',
            'user_id' => 'B22917863673',
            'event_name' => 'Default',
            'campaign_id' => '2619244',
            'postback_id' => '154127466',
        ],
        'ip' => '34.54.234.115',
        'signature_valid' => true,
        'ip_allowed' => true,
        'result' => IncomingPostbackResult::UnknownClick,
        'reject_reason' => 'no click matches 4e02319d9889b5ab9dcd338a30a6cab1',
        'created_at' => now(),
    ]);
}

function actingAsIncomingPostbackAdmin(): void
{
    Config::set('advault.require_admin_2fa', false);

    test()->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
}

it('lists payout, user, click and event on the incoming postbacks table', function () {
    actingAsIncomingPostbackAdmin();
    incomingPostbackRow();

    $this->get('/admin/incoming-postbacks')
        ->assertSuccessful()
        ->assertSee('MyChips')
        ->assertSee('Unknown click')
        ->assertSee('0.04')
        ->assertSee('0.02')
        ->assertSee('B22917863673')
        ->assertSee('4e02319d9889b5ab9dcd338a30a6cab1')
        ->assertSee('Default')
        ->assertSee('2619244');
});
