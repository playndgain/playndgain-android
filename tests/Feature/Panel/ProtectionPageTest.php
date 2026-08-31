<?php

use App\Enums\FraudAction;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\Protection;
use App\Models\FraudEvent;
use App\Models\IpProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

require_once __DIR__.'/../Fraud/FraudFixtures.php';

/**
 * The Protection page is the one screen every §9 question answers from: which
 * providers are healthy, are the local databases fresh, what does an address
 * look like, and what would the rules do about it. The riskiest surface on it
 * is the provider form — it edits an encrypted API key and must never leak
 * that key back to the browser or into the audit trail.
 */
beforeEach(function () {
    // A Livewire component tested outside a request has no panel, and every
    // Filament URL helper it touches then resolves against no routes.
    Filament::setCurrentPanel('admin');

    // The repeater otherwise keys each item with a random UUID on hydration,
    // which makes `providers.0.*` a moving target from one run to the next.
    // `Repeater::fake()` is Filament's own escape hatch for this, restoring
    // plain list indices for the duration of the test.
    Repeater::fake();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

it('saves provider settings and never echoes the key back', function () {
    $provider = IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'ProxyCheck.io']);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'enabled' => true, 'api_key' => 'new-key', 'timeout_ms' => 900, 'daily_cap' => 1000]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->fresh()->enabled)->toBeTrue()
        ->and($provider->fresh()->api_key)->toBe('new-key')
        ->and($provider->fresh()->timeout_ms)->toBe(900);
});

it('strips surrounding whitespace from a pasted provider key', function () {
    $provider = IpProvider::query()->create(['key' => 'ipqualityscore', 'name' => 'IPQualityScore']);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'enabled' => true, 'api_key' => "  new-key  \n", 'timeout_ms' => 900, 'daily_cap' => 200]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->fresh()->api_key)->toBe('new-key');
});

/**
 * The form field the operator sees is never given the real key: `mount()`
 * fills `api_key` with null and only the value the operator just typed can
 * ever appear in the page. A stored secret that round-tripped here would be
 * sitting in the Livewire snapshot embedded in the page HTML — readable by
 * anyone with dev tools open, not just the operator who set it.
 */
/**
 * The status cards read `api_key` on every GET. A leftover payload from a
 * rotated APP_KEY used to throw DecryptException and 500 the page — the
 * operator could not even reach the form to paste a replacement.
 */
it('renders when a stored key cannot be decrypted', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck',
        'name' => 'ProxyCheck.io',
        'enabled' => true,
    ]);

    $foreign = new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher'));

    DB::table('ip_providers')->where('id', $provider->id)->update([
        'api_key' => $foreign->encryptString('lost-key'),
    ]);

    Livewire::test(Protection::class)->assertSee('no key');
});

it('never round-trips a stored key to the browser', function () {
    IpProvider::query()->create([
        'key' => 'proxycheck',
        'name' => 'ProxyCheck.io',
        'enabled' => true,
        'api_key' => 'super-secret-value',
    ]);

    $html = Livewire::test(Protection::class)->instance()->getSchema('form')->toHtml();

    expect($html)->not->toContain('super-secret-value');
});

/**
 * An admin who ticks "enabled" on a provider with no key on file must be
 * told, not silently punished — a provider that is enabled but unusable
 * degrades every inconclusive address at 300 seconds instead of an hour,
 * forever, until someone notices.
 */
it('refuses to enable a provider with a blank key', function () {
    $provider = IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'ProxyCheck.io']);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'enabled' => true, 'api_key' => '']]])
        ->call('save')
        ->assertHasFormErrors(['providers.0.api_key' => 'required']);

    expect($provider->fresh()->enabled)->toBeFalse();
});

/**
 * Fix 1: a seeded row's `daily_cap` lands NULL, and `IpProvider::capSpent()`
 * treats NULL as uncapped — so without this the operator can enable a
 * provider and save with no spend ceiling at all, silently. Mirrors the
 * `api_key` refusal above; the two fields protect against the same failure
 * mode, an enabled row nobody finished configuring.
 */
it('refuses to enable a provider with a blank daily cap', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck',
        'name' => 'ProxyCheck.io',
        'api_key' => 'already-on-file',
    ]);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'enabled' => true, 'daily_cap' => '']]])
        ->call('save')
        ->assertHasFormErrors(['providers.0.daily_cap' => 'required']);

    expect($provider->fresh()->enabled)->toBeFalse();
});

/**
 * A provider that already has a key on file must not force the operator to
 * re-paste it on every unrelated save — the requirement is "enabled needs a
 * key somewhere", not "enabled needs a key in this request".
 */
it('lets an already-configured provider save with the key field left blank', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck',
        'name' => 'ProxyCheck.io',
        'api_key' => 'already-on-file',
    ]);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'enabled' => true, 'api_key' => '', 'priority' => 5, 'daily_cap' => 1000]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->fresh()->enabled)->toBeTrue()
        ->and($provider->fresh()->api_key)->toBe('already-on-file')
        ->and($provider->fresh()->priority)->toBe(5);
});

/**
 * A `ttl_seconds` of 0 makes the raw provider layer's `Cache::put` a no-op,
 * which silently re-bills the vendor on every merged-cache miss — the floor
 * has to be enforced by the form, not just documented in a helper text.
 */
it('refuses a cache lifetime below the floor', function () {
    $provider = IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'ProxyCheck.io']);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'ttl_seconds' => 60]]])
        ->call('save')
        ->assertHasFormErrors(['providers.0.ttl_seconds' => 'min']);
});

it('reports which local databases are present', function () {
    expect(collect(app(Protection::class)->getDatabasesProperty())->pluck('label'))
        ->toContain('Country', 'ASN', 'Proxy');
});

it('tests an address against the local reader without writing the shared cache', function () {
    Http::fake();

    Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest')
        ->assertHasNoErrors();

    // An operator testing a suspicious address must not thereby install a
    // verdict that then governs real traffic.
    expect(cache()->has('ip:v2:8.8.8.8'))->toBeFalse();
});

it('returns a mapped result row for an enabled, configured provider', function () {
    IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'enabled' => true, 'api_key' => 'k']);

    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '8.8.8.8' => ['proxy' => 'yes', 'type' => 'VPN', 'isocode' => 'US', 'asn' => 'AS15169', 'provider' => 'Google', 'risk' => 42],
    ])]);

    $results = Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest')
        ->get('testResults');

    $row = collect($results)->firstWhere('source', 'ProxyCheck.io');

    expect($row)->not->toBeNull()
        ->and($row['country'])->toBe('US')
        ->and($row['proxy_type'])->toBe('VPN')
        ->and($row['risk'])->toBe(42)
        ->and($row['ms'])->toBeInt();
});

/**
 * `AbstractIpProvider::lookup()` has no cap check of its own — that gate
 * lives in `IpProvider::active()`/`available()`, which the test tool
 * deliberately does not call (it wants a row for every enabled provider,
 * not a silently shorter list). So `testProvider()` has to check the cap
 * itself, before ever reaching the driver.
 */
it('skips a provider whose daily cap is already spent, and calls nothing', function () {
    Http::fake();

    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'enabled' => true, 'api_key' => 'k', 'daily_cap' => 5,
    ]);
    cache()->put($provider->counterKey(), 5, now()->addDay());

    $results = Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest')
        ->get('testResults');

    $row = collect($results)->firstWhere('source', 'ProxyCheck.io');

    expect($row['skipped'])->toBeTrue()
        ->and($row['note'])->toContain('daily cap reached (5/5)');

    // The only assertion shape that actually proves the spend gate: no
    // request left the process at all.
    Http::assertNothingSent();
});

/**
 * The deliberate half of the asymmetry: an open breaker is called anyway,
 * because this button is the only way an operator can find out whether a
 * vendor has recovered. It has to compose with the breaker's own machinery
 * rather than bypass it, so a successful test closes the breaker exactly as
 * a real recovery would.
 */
it('calls a provider whose breaker is open, and closes it on a good answer', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'enabled' => true, 'api_key' => 'k',
        'consecutive_failures' => 5, 'circuit_failures' => 5, 'circuit_cooldown_minutes' => 10,
        'circuit_opened_at' => now(),
    ]);

    expect($provider->breakerOpen())->toBeTrue();

    Http::fake(['proxycheck.io/*' => Http::response(['status' => 'ok', '8.8.8.8' => ['proxy' => 'no']])]);

    Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'proxycheck.io'));

    expect($provider->fresh()->breakerOpen())->toBeFalse();
});

it('shows a row and logs a warning for a provider with no registered driver', function () {
    Log::spy();

    IpProvider::query()->create(['key' => 'made-up', 'name' => 'Made Up', 'enabled' => true, 'api_key' => 'k']);

    $results = Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest')
        ->get('testResults');

    $row = collect($results)->firstWhere('source', 'Made Up');

    expect($row['skipped'])->toBeFalse()
        ->and($row['note'])->toBe('no driver registered for this key');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'IP provider row has no registered driver'
            && $context['key'] === 'made-up'
    );
});

it('produces an error row when the vendor call fails', function () {
    IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'enabled' => true, 'api_key' => 'k']);

    Http::fake(['proxycheck.io/*' => Http::response('', 500)]);

    $results = Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest')
        ->get('testResults');

    $row = collect($results)->firstWhere('source', 'ProxyCheck.io');

    expect($row['skipped'])->toBeFalse()
        ->and($row['note'])->toBe('HTTP 500');
});

/**
 * `getRulesProperty()` used to report only a rule's own columns — action,
 * weight, enabled — leaving out the counts that are this section's whole
 * reason to sit next to provider health and the test tool (docs/07 §7): a
 * rule that never fires is dead weight, a rule firing on 40% of traffic is
 * miscalibrated, and neither is visible without the number. This proves the
 * page reads `FraudRuleResource::triggerCounts()` — the one place that count
 * is computed — rather than a second query, and that the 24-hour window is a
 * real boundary rather than a label pasted on the 7-day one.
 */
it('reports 24-hour and 7-day fire counts from the same rows FraudRuleResource counts', function () {
    fraudRule('datacenter_ip', FraudStage::PreClick, FraudAction::Block);
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Block);

    protectionFraudEvent('datacenter_ip', now()->subHours(2));  // inside both windows
    protectionFraudEvent('datacenter_ip', now()->subDays(3));   // inside 7d, outside 24h
    protectionFraudEvent('datacenter_ip', now()->subDays(10));  // outside both

    $rules = collect(app(Protection::class)->getRulesProperty());
    $datacenter = $rules->firstWhere('key', 'datacenter_ip');
    $vpn = $rules->firstWhere('key', 'vpn_proxy_ip');

    expect($datacenter['fired_24h'])->toBe(1)
        ->and($datacenter['fired_7d'])->toBe(2)
        ->and($vpn['fired_24h'])->toBe(0)
        ->and($vpn['fired_7d'])->toBe(0);
});

/**
 * A bare `fraud_event` row at a given time. The subject is unrelated to any
 * real click — only `rule_key` and `created_at` matter to the count query
 * this test is proving, the same shape `FraudFixtures::fraudSeedBlocks()`
 * uses for the auto-ban window.
 */
function protectionFraudEvent(string $ruleKey, mixed $createdAt): void
{
    FraudEvent::query()->create([
        'rule_key' => $ruleKey,
        'stage' => FraudStage::PreClick,
        'subject_type' => 'test',
        'subject_id' => 1,
        'action_taken' => FraudActionTaken::Blocked,
        'context' => [],
        'created_at' => $createdAt,
    ]);
}
