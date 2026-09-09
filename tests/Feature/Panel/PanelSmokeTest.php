<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Enums\IncomingPostbackResult;
use App\Enums\PublisherStatus;
use App\Enums\SyncStatus;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\BannedEndUser;
use App\Models\Blacklist;
use App\Models\Conversion;
use App\Models\DailyStat;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Models\NetworkSync;
use App\Models\NewsletterSubscriber;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\OutgoingPostback;
use App\Models\PaymentMethod;
use App\Models\PayoutStatement;
use App\Models\Publisher;
use App\Models\User;
use App\Models\Withdrawal;
use Database\Factories\ConsentFactory;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Facades\Config;

/**
 * Every page in both panels, rendered with real rows in the tables.
 *
 * PHPStan cannot tell whether a Filament column, filter or action is wired
 * correctly — only rendering can. Without this, a mistyped closure argument in
 * one of forty resources is found by a human clicking around.
 *
 * The seeded rows matter: an empty table renders no columns, so a table that
 * only breaks on real data would still pass.
 */
function adminWithTwoFactor(): User
{
    // phpunit.xml pins the 2FA requirement on, and the panel reads that at boot
    // — so a smoke-test admin has to actually have a secret, not just a
    // confirmation timestamp.
    return User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);
}

function seedEverything(): Publisher
{
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Approved]);
    $pending = Publisher::factory()->pending()->create();

    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    $network = Network::factory()->create();
    $offer = Offer::factory()->create(['network_id' => $network->id]);

    NetworkSync::create([
        'network_id' => $network->id,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'duration_ms' => 1_200,
        'offers_seen' => 42,
        'offers_created' => 2,
        'offers_updated' => 40,
        'offers_deactivated' => 3,
        'status' => SyncStatus::Ok,
        'created_at' => now(),
    ]);

    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
    ]);

    $conversion = Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'offer_click_id' => $click->id,
        'click_id' => $click->click_id,
        'status' => ConversionStatus::Pending,
    ]);

    IncomingPostback::create([
        'network_id' => $network->id,
        'driver' => $network->driver,
        'method' => 'GET',
        'query' => [
            'click_id' => $click->click_id,
            'payout' => '0.04',
            'user_payout' => '0.02',
            'user_id' => 'USER_1',
            'event_name' => 'Default',
            'campaign_id' => '2619244',
        ],
        'ip' => '127.0.0.1',
        'signature_valid' => true,
        'ip_allowed' => true,
        'result' => IncomingPostbackResult::UnknownClick,
        'reject_reason' => 'no click matches 4e02319d9889b5ab9dcd338a30a6cab1',
        'created_at' => now(),
    ]);

    OutgoingPostback::create([
        'conversion_id' => $conversion->id,
        'app_id' => $app->id,
        'kind' => 'credit',
        'url' => 'https://publisher.example/cb?sig=REDACTED',
        'method' => 'GET',
        'attempt' => 1,
        'status' => 'success',
        'http_status' => 200,
        'duration_ms' => 120,
        'created_at' => now(),
    ]);

    $method = PaymentMethod::create([
        'publisher_id' => $publisher->id,
        'type' => 'paypal',
        'details' => ['email' => 'payee@example.com'],
        'is_default' => true,
    ]);

    $withdrawal = Withdrawal::create([
        'publisher_id' => $publisher->id,
        'payment_method_id' => $method->id,
        'amount' => '25.000000',
        'method' => 'paypal',
        'payment_details' => ['email' => 'payee@example.com'],
        'status' => 'requested',
        'requested_at' => now(),
    ]);

    PayoutStatement::create([
        'publisher_id' => $publisher->id,
        'period' => now()->startOfMonth()->subMonth()->toDateString(),
        'gross' => '25.000000',
        'chargebacks' => '0.000000',
        'adjustments' => '0.000000',
        'net' => '25.000000',
        'status' => 'pending',
        'due_date' => now()->startOfMonth()->endOfMonth()->toDateString(),
        'withdrawal_id' => $withdrawal->id,
        'minimum_payout' => '20.00',
        'generated_at' => now(),
    ]);

    Blacklist::create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'type' => 'offer',
        'value' => (string) $offer->id,
    ]);

    BannedEndUser::create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'end_user_id' => 'user-1',
        'banned_by' => 'publisher',
        'reason' => 'chargeback ring',
    ]);

    ConsentFactory::new()->create(['app_id' => $app->id, 'end_user_id' => 'USER_1']);

    DailyStat::create([
        'date' => now()->toDateString(),
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'country' => 'US',
        'clicks' => 10,
        'conversions' => 1,
        'reversals' => 0,
        'revenue' => '1.000000',
        'payout' => '0.700000',
    ]);

    FraudEvent::query()->create([
        'rule_key' => 'vpn_ip',
        'stage' => FraudStage::PreClick,
        'subject_type' => $click->getMorphClass(),
        'subject_id' => $click->getKey(),
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'end_user_id' => $click->end_user_id,
        'action_taken' => FraudActionTaken::Blocked,
        'context' => ['reason' => 'datacenter'],
        'created_at' => now(),
    ]);

    FraudRule::query()->firstOrCreate(['key' => 'vpn_ip'], [
        'name' => 'Datacenter or proxy IP',
        'description' => 'Blocks clicks from known datacenter ranges.',
        'stage' => 'pre_click',
        'action' => 'block',
        'enabled' => true,
    ]);

    AuditLog::create([
        'user_id' => $pending->user_id,
        'action' => 'publisher.approved',
        'subject_type' => Publisher::class,
        'subject_id' => $pending->id,
        'after' => ['status' => 'approved'],
        'created_at' => now(),
    ]);

    // One of each state, so the mailing list renders both badge branches and
    // the row action that is only visible on the other one. firstOrCreate
    // because the list is global rather than per-publisher, and this seeder is
    // called twice in the isolation test below.
    NewsletterSubscriber::firstOrCreate(
        ['email' => 'dana@example.com'],
        ['source' => '/', 'ip' => '203.0.113.0'],
    );
    NewsletterSubscriber::firstOrCreate(
        ['email' => 'sam@example.com'],
        ['source' => '/docs/quick-start', 'unsubscribed_at' => now()->subDay()],
    );

    return $publisher;
}

it('renders every admin page', function (string $path) {
    // 2FA is required in production and pinned on in phpunit.xml; this suite is
    // about the pages, not about the authenticator flow.
    Config::set('playndgain.require_admin_2fa', false);

    seedEverything();

    $this->actingAs(adminWithTwoFactor());

    $this->get($path)->assertSuccessful();
})->with([
    '/admin',
    '/admin/analytics',
    '/admin/networks',
    '/admin/offers',
    '/admin/network-syncs',
    '/admin/publishers',
    '/admin/apps',
    '/admin/signup-queue',
    '/admin/conversions',
    '/admin/withdrawals',
    '/admin/ledger-entries',
    '/admin/profit-history',
    '/admin/review-queue',
    '/admin/fraud-rules',
    '/admin/fraud-events',
    '/admin/banned-end-users',
    '/admin/offer-clicks',
    '/admin/incoming-postbacks',
    '/admin/outgoing-postbacks',
    '/admin/audit-logs',
    '/admin/settings',
    '/admin/admin-users',
    '/admin/newsletter-subscribers',
    '/admin/health',
    '/admin/announcements',
    '/admin/announcements/create',
    '/admin/emails',
]);

it('renders every publisher page', function (string $path) {
    $publisher = seedEverything();

    $this->actingAs($publisher->user);

    $this->get($path)->assertSuccessful();
})->with([
    '/dashboard',
    '/dashboard/apps',
    '/dashboard/apps/create',
    '/dashboard/offers',
    '/dashboard/statistics',
    '/dashboard/transactions',
    '/dashboard/offer-clicks',
    '/dashboard/postback-reports',
    '/dashboard/payments',
    '/dashboard/blacklists',
    '/dashboard/banned-end-users',
]);

it('renders the record pages that carry the most custom code', function () {
    $publisher = seedEverything();
    $conversion = Conversion::query()->withoutGlobalScopes()->firstOrFail();
    $network = Network::query()->firstOrFail();
    $app = App::query()->withoutGlobalScopes()->firstOrFail();

    Config::set('playndgain.require_admin_2fa', false);
    $this->actingAs(adminWithTwoFactor());

    $this->get("/admin/publishers/{$publisher->id}")->assertSuccessful();
    $this->get("/admin/publishers/{$publisher->id}/edit")->assertSuccessful();
    $this->get("/admin/conversions/{$conversion->id}")->assertSuccessful();
    $this->get("/admin/networks/{$network->id}/edit")->assertSuccessful();
    $this->get("/admin/apps/{$app->id}")->assertSuccessful();
    $this->get("/admin/apps/{$app->id}/edit")->assertSuccessful();

    $this->actingAs($publisher->user);

    $this->get("/dashboard/apps/{$app->id}/edit")->assertSuccessful();
});

it('keeps a publisher out of the admin panel', function () {
    $publisher = seedEverything();

    $this->actingAs($publisher->user);
    $this->get('/admin')->assertForbidden();
});

it('lets an admin open the publisher panel, and shows them none of it', function () {
    $publisher = seedEverything();

    Config::set('playndgain.require_admin_2fa', false);
    $this->actingAs(adminWithTwoFactor());

    // Reachable, so an admin can check the surface their customers use and
    // switch back without signing out. Empty, because PublisherScope keys on
    // the panel: this one is single-tenant and an admin is not a tenant.
    $this->get('/dashboard')->assertSuccessful();

    $this->get('/dashboard/apps')
        ->assertSuccessful()
        ->assertDontSee($publisher->apps()->value('name'));
});

it('does not put another publisher\'s rows in a scoped table', function () {
    $publisher = seedEverything();
    $stranger = seedEverything();

    $this->actingAs($publisher->user);

    // Asserted at the query the resource builds, which is what a leak would
    // come through — not at the rendered HTML.
    expect(Conversion::query()->pluck('publisher_id')->unique()->all())->toBe([$publisher->id])
        ->and(OfferClick::query()->pluck('publisher_id')->unique()->all())->toBe([$publisher->id])
        ->and(Withdrawal::query()->pluck('publisher_id')->unique()->all())->toBe([$publisher->id])
        ->and(PayoutStatement::query()->pluck('publisher_id')->unique()->all())->toBe([$publisher->id])
        ->and($stranger->id)->not->toBe($publisher->id);
});
