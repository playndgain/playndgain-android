<?php

namespace App\Filament\Admin\Pages;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Filament\Admin\Resources\FraudRules\FraudRuleResource;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\IpVerdict;
use App\Fraud\MaxMindIpIntelligence;
use App\Fraud\Providers\IpProviderRegistry;
use App\Fraud\RuleRegistry;
use App\Models\AuditLog;
use App\Models\FraudRule;
use App\Models\IpProvider;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * One page for everything that decides whether an address may see a wall —
 * the spec's §9.
 *
 * The four sections answer four different questions and are on one page
 * because an operator asking one of them is usually about to ask another:
 * which providers are running and are they healthy, are the local databases
 * fresh, what does this specific address look like, and what would we do
 * about it.
 */
class Protection extends Page
{
    use InteractsWithFormActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.protection';

    /** @var array<string, mixed> */
    public array $data = [];

    public string $testIp = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $testResults = null;

    /**
     * `api_key` is deliberately left out of the fill — see the field's
     * definition in `form()`. Everything else about the row is safe to
     * hydrate straight from the model.
     */
    public function mount(): void
    {
        $this->getSchema('form')?->fill([
            'providers' => IpProvider::query()->orderBy('priority')->get()->map(fn (IpProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'enabled' => $p->enabled,
                'priority' => $p->priority,
                'api_key' => null,
                'timeout_ms' => $p->timeout_ms,
                'daily_cap' => $p->daily_cap,
                'ttl_seconds' => $p->ttl_seconds,
                'fail_open' => $p->fail_open,
            ])->all(),
        ]);
    }

    /**
     * The form and its submit button, composed the way Filament v5 composes a
     * page: the view prints `$this->content` and the footer actions come from
     * the schema, not from a Blade component.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Providers')
                ->description('A paid lookup is spent only on an address the local databases cannot judge. Three brakes — timeout, breaker, daily cap — and any of them tripping leaves the local verdict standing.')
                ->schema([
                    Repeater::make('providers')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? ''))
                        ->columns(4)
                        ->schema([
                            // Required. A Repeater dehydrates only the
                            // components it declares, so without this the id
                            // filled in `mount()` never comes back and `save()`
                            // silently updates nothing.
                            Hidden::make('id'),
                            Toggle::make('enabled'),
                            TextInput::make('priority')->numeric()->required()->minValue(1)->maxValue(255)
                                ->helperText('Ascending. First conclusive answer wins.'),
                            TextInput::make('api_key')
                                ->password()
                                ->revealable()
                                ->maxLength(191)
                                // Chrome/password-managers treat the first
                                // text+password pair on a page as a login form.
                                // These attributes stop a saved browser login
                                // from silently landing here — which matters
                                // more than usual once a blank field means
                                // "keep the stored key": an autofilled value
                                // would overwrite it on save.
                                ->autocomplete('new-password')
                                ->extraInputAttributes([
                                    'data-1p-ignore' => 'true',
                                    'data-lpignore' => 'true',
                                    'data-bwignore' => 'true',
                                    'data-form-type' => 'other',
                                ])
                                ->helperText(function (Get $get): string {
                                    $key = IpProvider::query()->find($get('id'))?->key;

                                    return $key === 'ipqualityscore'
                                        ? 'Write-only. Paste the Proxy Detection private API key from the IPQualityScore dashboard (API Settings). Account passwords and email/phone keys are rejected. Leave blank to keep the stored key.'
                                        : 'Write-only — never prefilled with the stored key, so it cannot round-trip to the browser. Leave blank to keep it; the status card below shows whether one is on file.';
                                })
                                // Required only when there is neither a stored
                                // key nor a new one typed in — an operator
                                // saving an already-configured, already-enabled
                                // row must not be made to re-paste the key
                                // every time.
                                ->required(fn (Get $get): bool => (bool) $get('enabled')
                                    && ! filled(IpProvider::query()->find($get('id'))?->api_key)),
                            TextInput::make('timeout_ms')->label('Timeout')->numeric()->required()->suffix('ms')->minValue(100)->maxValue(2000)
                                // The chain consults enabled providers
                                // sequentially, so the render-path worst case
                                // is this figure times the number of enabled
                                // providers — two at the old 5000ms ceiling
                                // was a ten-second offerwall.
                                ->helperText('This sits on the wall render path. Worst case is this value times the number of enabled providers, on a user-visible render.'),
                            TextInput::make('daily_cap')->numeric()->minValue(1)
                                ->helperText('Required while enabled — uncapped is a decision rather than a default, and it can only be chosen by clearing this on a disabled row.')
                                // A clean local verdict is inconclusive by
                                // design, so ordinary traffic escalates at
                                // close to one lookup per address — an
                                // uncapped row is not a fraud-tail safety net,
                                // it is an unbounded bill for normal render
                                // volume nobody chose. Mirrors `api_key`'s own
                                // required() just above.
                                ->required(fn (Get $get): bool => (bool) $get('enabled')),
                            TextInput::make('ttl_seconds')->label('Cache lifetime')->numeric()->required()->minValue(3600)
                                ->helperText('A day is right: proxy status changes on weekly timescales, and an hour re-bills every address every hour.'),
                            Toggle::make('fail_open')
                                ->helperText('On, a failed lookup leaves the local verdict standing. Off refuses the address.'),
                        ]),
                ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save provider settings')->submit('save'),
        ];
    }

    /**
     * Persists every row the repeater carries, id-matched back to its model
     * rather than trusted by position — the repeater keys its own items with
     * whatever `Repeater::hydrateItems()` assigned them, never the database
     * id, so the `Hidden` id field is the only reliable link back to a row. A
     * blank `api_key` means "unchanged", never "clear it" (see the field's
     * own helper text); every other field is a straight overwrite.
     */
    public function save(): void
    {
        $rows = (array) ($this->getSchema('form')?->getState()['providers'] ?? []);

        foreach ($rows as $row) {
            $provider = IpProvider::query()->find($row['id'] ?? null);

            if ($provider === null) {
                continue;
            }

            $before = self::auditSnapshot($provider);

            $attributes = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'priority' => (int) $row['priority'],
                'timeout_ms' => (int) $row['timeout_ms'],
                'daily_cap' => $row['daily_cap'] === null || $row['daily_cap'] === '' ? null : (int) $row['daily_cap'],
                'ttl_seconds' => (int) $row['ttl_seconds'],
                'fail_open' => (bool) ($row['fail_open'] ?? false),
            ];

            // A blank secret means "unchanged", not "clear it" — the same rule
            // EditNetwork's credentials merge follows, for the same reason:
            // the field is never prefilled with the stored key, so a blank
            // submission is the normal shape of "I did not touch this", not an
            // instruction to wipe it. There is deliberately no way to clear a
            // key from this form; disabling the provider is how you stop using
            // one.
            if (filled($row['api_key'] ?? null)) {
                $attributes['api_key'] = trim((string) $row['api_key']);
            }

            $provider->update($attributes);

            // The key itself is never in the audit trail, only the fact that
            // it moved — `auditSnapshot()`'s `api_key_set` records a change of
            // state, not a value.
            AuditLog::record('ip_provider.updated', $provider, $before, self::auditSnapshot($provider));
        }

        Notification::make()->title('Protection settings saved')->success()->send();
    }

    /**
     * The before and after side of a provider's audit entry share this shape
     * so the two cannot drift apart. The key itself is never in it.
     *
     * @return array<string, mixed>
     */
    private static function auditSnapshot(IpProvider $provider): array
    {
        return [
            ...$provider->only(['enabled', 'priority', 'timeout_ms', 'daily_cap', 'ttl_seconds', 'fail_open']),
            'api_key_set' => filled($provider->api_key),
        ];
    }

    /**
     * Live state next to the form, because an enabled and quiet provider must
     * look different at a glance from an enabled and failing one.
     *
     * @return list<array<string, mixed>>
     */
    public function getProviderStatesProperty(): array
    {
        return IpProvider::query()->orderBy('priority')->get()->map(fn (IpProvider $p): array => [
            'name' => $p->name,
            'enabled' => $p->enabled,
            'configured' => filled($p->api_key),
            'spent' => $p->spent(),
            'cap' => $p->daily_cap,
            'breaker' => $p->breakerOpen() ? 'open until '.$p->circuit_opened_at?->addMinutes((int) $p->circuit_cooldown_minutes)->diffForHumans() : 'closed',
            'last_error' => $p->last_error,
            'last_error_at' => $p->last_error_at?->diffForHumans(),
            'ok' => $p->available() || ! $p->enabled,
        ])->all();
    }

    /**
     * @return list<array{label: string, value: string, ok: bool}>
     */
    public function getDatabasesProperty(): array
    {
        $rows = [];

        foreach (MaxMindIpIntelligence::DATABASES as $label => $file) {
            $path = MaxMindIpIntelligence::file($file);
            $present = is_file($path);

            $rows[] = [
                'label' => $label,
                'value' => $present
                    ? Number::fileSize(filesize($path) ?: 0).', updated '.CarbonImmutable::createFromTimestamp(filemtime($path) ?: 0)->diffForHumans()
                    : 'missing',
                'ok' => $present,
            ];
        }

        return $rows;
    }

    public function updateDatabasesAction(): Action
    {
        return Action::make('updateDatabases')
            ->label('Update now')
            ->requiresConfirmation()
            ->modalDescription('Downloads roughly 1.5 GB. The wall keeps serving from the current files while it runs.')
            ->action(function (): void {
                Artisan::queue('geoip:update');

                Notification::make()->title('Database refresh queued')->success()->send();
            });
    }

    /**
     * Direct to each provider, and nothing written to the merged `ip:v2:` key:
     * the answer must be what the provider says now rather than what it said
     * an hour ago, and an operator testing a suspicious address must not
     * thereby install a verdict that then governs real traffic.
     *
     * The local reader does populate its own `ip:local:v2:` entry, which is
     * harmless — it is derived from files on disk and identical to what the
     * next real request would compute anyway.
     *
     * The two spend brakes and the breaker are treated asymmetrically, on
     * purpose — see `testProvider()`.
     */
    public function runTest(): void
    {
        $ip = trim($this->testIp);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            Notification::make()->title('Not an IP address')->danger()->send();

            return;
        }

        $results = [];
        $started = microtime(true);
        $local = (new MaxMindIpIntelligence)->inspect($ip);
        $results[] = self::row('Local databases', $local, $started);

        foreach (IpProvider::query()->where('enabled', true)->orderBy('priority')->get() as $config) {
            $results[] = self::testProvider($ip, $config);
        }

        $this->testResults = $results;
    }

    /**
     * One provider's row, with the spend brakes and the breaker treated
     * asymmetrically on purpose.
     *
     * A blank key or a spent daily cap are never called — skipped instead,
     * with the reason shown, because calling anyway would either fail
     * outright or spend past a ceiling the operator configured, and this
     * page's whole second requirement is about not silently re-billing a
     * vendor.
     *
     * An open breaker **is** called. `IpProvider::breakerOpen()` exists to
     * keep a down provider's latency off the wall's render path, and this
     * button is not that path. Testing a braked provider is the only way an
     * operator can check whether a vendor has recovered, and it composes for
     * free with `AbstractIpProvider::lookup()`'s own bookkeeping: a failing
     * call records another failure and the breaker stays open, a succeeding
     * one records a success and closes it. That makes Test the manual
     * half-open probe the automatic path is deliberately never allowed to be.
     *
     * @return array<string, mixed>
     */
    private static function testProvider(string $ip, IpProvider $config): array
    {
        if (! filled($config->api_key)) {
            return ['source' => $config->name, 'note' => 'skipped — no key configured', 'skipped' => true, 'ms' => 0];
        }

        if ($config->capSpent()) {
            return ['source' => $config->name, 'note' => "skipped — daily cap reached ({$config->spent()}/{$config->daily_cap})", 'skipped' => true, 'ms' => 0];
        }

        $driver = app(IpProviderRegistry::class)->resolve($config->key);

        // Same shape as `FraudPipeline::evaluate()` and
        // `WallGate::protectionBlock()`: a row that exists with no class
        // behind it is a misconfiguration, logged loudly rather than taking
        // anything down — and, on this page specifically, shown rather than
        // silently dropped from the results an operator is reading.
        if ($driver === null) {
            Log::warning('IP provider row has no registered driver', ['key' => $config->key]);

            return ['source' => $config->name, 'note' => 'no driver registered for this key', 'skipped' => false, 'ms' => 0];
        }

        $started = microtime(true);
        $answer = $driver->lookup($ip, $config);

        return $answer === null
            ? ['source' => $config->name, 'note' => $config->fresh()->last_error ?? 'no answer', 'skipped' => false, 'ms' => self::ms($started)]
            : self::row($config->name, $answer, $started);
    }

    /** @return array<string, mixed> */
    private static function row(string $source, IpVerdict $verdict, float $started): array
    {
        return [
            'source' => $source,
            'country' => $verdict->country,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'proxy_type' => $verdict->proxyType?->value,
            'usage_type' => $verdict->usageType,
            'threat' => $verdict->threat,
            'risk' => $verdict->riskScore,
            'ms' => self::ms($started),
        ];
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * The rules this page's signals feed, read-only — the same rows and the
     * same trigger counts as the Fraud Rules resource, filtered to the family
     * that gates render, so an operator can see whether a threshold is
     * miscalibrated without leaving the page. Tuning still happens on the
     * Fraud Rules resource: a second editable surface over the same
     * `fraud_rules` rows would be a second write path and a second audit
     * trail for one signal.
     *
     * Counts come from `FraudRuleResource::triggerCounts()` rather than a
     * second query — one definition of how a count is computed, called from
     * both places.
     *
     * `gates_render` is enabled-and-block, not "always refuses a render":
     * `vpn_proxy_ip` triggers on its unclassified fallback too, and
     * `VpnProxyIpRule::noticeKey()` withholds the render refusal for that one
     * branch while still gating render for an explicit consumer class. A
     * per-row boolean cannot say "sometimes" honestly, so the caveat lives
     * here and on the page next to the column instead of in a value this
     * column cannot express.
     *
     * @return list<array<string, mixed>>
     */
    public function getRulesProperty(): array
    {
        $registry = app(RuleRegistry::class);
        $fired24h = FraudRuleResource::triggerCounts(1);
        $fired7d = FraudRuleResource::triggerCounts(7);

        return FraudRule::query()
            ->where('stage', FraudStage::PreClick)
            ->orderBy('key')
            ->get()
            ->filter(fn (FraudRule $rule): bool => $registry->resolve($rule->key) instanceof RenderGateRule)
            ->map(fn (FraudRule $rule): array => [
                'key' => $rule->key,
                'name' => $rule->name,
                'action' => $rule->action->value,
                'weight' => $rule->score_weight,
                'enabled' => $rule->enabled,
                'gates_render' => $rule->enabled && $rule->action === FraudAction::Block,
                'fired_24h' => $fired24h[$rule->key] ?? 0,
                'fired_7d' => $fired7d[$rule->key] ?? 0,
            ])
            ->values()
            ->all();
    }
}
