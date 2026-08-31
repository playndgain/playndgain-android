<?php

namespace App\Filament\Admin\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Identity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Platform settings — docs/07 §9.
 *
 * Money and operational toggles live in the `settings` table. Identity
 * (name, support mail, invoice issuer) is the opposite: `.env` is the default
 * and a non-empty setting is an override so an admin can rename the product
 * without a deploy. Blank means "use env".
 */
class Settings extends Page
{
    use InteractsWithFormActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->getSchema('form')?->fill([
            'platform_name' => (string) (Setting::get('platform_name') ?? ''),
            'support_email' => (string) (Setting::get('support_email') ?? ''),
            'invoice_company_name' => (string) (Setting::get('invoice_company_name') ?? ''),
            'invoice_address' => (string) (Setting::get('invoice_address') ?? ''),
            'invoice_city' => (string) (Setting::get('invoice_city') ?? ''),
            'invoice_country' => (string) (Setting::get('invoice_country') ?? ''),
            'invoice_vat' => (string) (Setting::get('invoice_vat') ?? ''),
            'invoice_website' => (string) (Setting::get('invoice_website') ?? ''),
            'invoice_email' => (string) (Setting::get('invoice_email') ?? ''),
            'invoice_directors' => (string) (Setting::get('invoice_directors') ?? ''),
            'invoice_vat_rate' => (string) (Setting::get('invoice_vat_rate') ?? ''),
            'default_rev_share' => Setting::get('default_rev_share', config('advault.default_rev_share')),
            'default_min_payout' => Setting::get('default_min_payout', config('advault.default_min_payout')),
            'signups_enabled' => (bool) Setting::get('signups_enabled', true),
            'auto_approve_publishers' => (bool) Setting::get('auto_approve_publishers', false),
            'wall_offers_limit' => Setting::get('wall_offers_limit', 100),
            'consent_required_countries' => json_decode((string) Setting::get('consent_required_countries', '[]'), true) ?: [],
            'alert_emails' => json_decode((string) Setting::get('alert_emails', '[]'), true) ?: [],
            'alert_cooldown_minutes' => Setting::get('alert_cooldown_minutes', config('advault.alerts.cooldown_minutes')),
            'alert_postback_failure_rate' => Setting::get('alert_postback_failure_rate', config('advault.alerts.postback_failure_rate')),
            'alert_queue_depth' => Setting::get('alert_queue_depth', config('advault.alerts.queue_depth')),
            'alert_conversion_silence_hours' => Setting::get('alert_conversion_silence_hours', config('advault.alerts.conversion_silence_hours')),
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
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Platform')->columns(2)->schema([
                    TextInput::make('platform_name')
                        ->maxLength(191)
                        ->placeholder(fn () => (string) config('app.name'))
                        ->helperText('Leave blank to use APP_NAME from .env. A saved value overrides it on the site, mail chrome and panels.'),
                    TextInput::make('support_email')
                        ->email()
                        ->maxLength(191)
                        ->placeholder(fn () => (string) config('advault.support_email'))
                        ->helperText('Leave blank to use ADVAULT_SUPPORT_EMAIL, or MAIL_FROM_ADDRESS if that is unset.'),
                ]),

                Section::make('Credit-note issuer')->columns(2)->schema([
                    TextInput::make('invoice_company_name')
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['name'])
                        ->helperText('Legal name on publisher payout PDFs. Blank follows INVOICE_COMPANY_NAME, then APP_NAME.'),
                    TextInput::make('invoice_vat')
                        ->label('VAT number')
                        ->maxLength(64)
                        ->placeholder(fn () => Identity::invoice()['vat'] ?: 'INVOICE_VAT'),
                    TextInput::make('invoice_address')
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['address'] ?: 'INVOICE_ADDRESS')
                        ->columnSpanFull(),
                    TextInput::make('invoice_city')
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['city'] ?: 'INVOICE_CITY'),
                    TextInput::make('invoice_country')
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['country'] ?: 'INVOICE_COUNTRY'),
                    TextInput::make('invoice_website')
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['website'])
                        ->helperText('Blank follows INVOICE_WEBSITE, then APP_URL.'),
                    TextInput::make('invoice_email')
                        ->email()
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['email'])
                        ->helperText('Blank follows INVOICE_EMAIL, then the support address.'),
                    TextInput::make('invoice_directors')
                        ->maxLength(191)
                        ->placeholder(fn () => Identity::invoice()['directors'] ?: 'INVOICE_DIRECTORS')
                        ->columnSpanFull(),
                    TextInput::make('invoice_vat_rate')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->placeholder(fn () => Identity::invoice()['vat_rate']),
                ]),

                Section::make('Defaults for new publishers')->columns(2)->schema([
                    TextInput::make('default_rev_share')->numeric()->suffix('%')->required()->minValue(1)->maxValue(100),
                    TextInput::make('default_min_payout')->numeric()->prefix('$')->required()->minValue(1),
                ]),

                Section::make('Signups')->columns(2)->schema([
                    Toggle::make('signups_enabled')
                        ->helperText('Closing signup withdraws the registration page only. Existing publishers keep signing in.'),
                    Toggle::make('auto_approve_publishers')
                        ->helperText('The manual gate is the cheapest fraud control on the platform. Leave this off.'),
                ]),

                Section::make('Offerwall')->columns(2)->schema([
                    TextInput::make('wall_offers_limit')->numeric()->required()->minValue(10)->maxValue(500),
                    Select::make('icon_proxy')
                        ->options(['on' => 'Proxy and cache icons', 'off' => 'Hotlink the network'])
                        ->default('on'),
                ]),

                Section::make('Compliance')->schema([
                    TagsInput::make('consent_required_countries')
                        ->label('Consent gate countries')
                        ->helperText('ISO-2 codes. Wall traffic from these sees the consent gate before anything is stored.'),
                ]),

                Section::make('Alerts')->schema([
                    TagsInput::make('alert_emails')
                        ->label('Alert recipients')
                        ->helperText('Where drift, sync failures and postback alerts go. Empty means the application log is the only record.'),

                    TextInput::make('alert_cooldown_minutes')
                        ->label('Cooldown per alert')
                        ->numeric()->suffix('minutes')->required()->minValue(0)
                        ->helperText('One email per signal per window; the log still records every occurrence. Zero sends every one — a nightly drift run across every publisher is what that costs.'),
                ]),

                // docs/07 §9 puts the thresholds in settings rather than in a
                // deploy, because the number that is noise on this platform is
                // the number that is an outage on the next one.
                Section::make('Alert thresholds')->columns(3)->schema([
                    TextInput::make('alert_postback_failure_rate')
                        ->label('Postback failure rate')
                        ->numeric()->suffix('%')->required()->minValue(1)->maxValue(100)
                        ->helperText('Global, over 15 minutes. Above this it is us, not one publisher.'),

                    TextInput::make('alert_queue_depth')
                        ->label('Postbacks queue depth')
                        ->numeric()->required()->minValue(1)
                        ->helperText('Conversions credited but publishers not yet told.'),

                    TextInput::make('alert_conversion_silence_hours')
                        ->label('Conversion silence')
                        ->numeric()->suffix('hours')->required()->minValue(1)
                        ->helperText('Only checked during normal hours, and only once the platform has had traffic in the last day.'),
                ]),
            ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save settings')->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->getSchema('form')?->getState() ?? [];

        $before = Setting::cached();

        foreach ($data as $key => $value) {
            Setting::set($key, match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_array($value) => json_encode($value),
                default => (string) $value,
            });
        }

        // The actor is also the subject: settings belong to the platform, not to
        // a row, and the audit table is morph-keyed.
        $actor = User::findOrFail(Auth::id());

        AuditLog::record('settings.updated', $actor, $before, Setting::cached(), $actor);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
