<?php

namespace App\Filament\Publisher\Auth;

use App\Enums\AppStatus;
use App\Enums\PublisherStatus;
use App\Enums\UserRole;
use App\Filament\Publisher\Pages\Welcome;
use App\Models\Setting;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * Publisher signup, four steps: account → traffic → first placement → review.
 *
 * Filament's default Register page would create a bare user with no role and no
 * publisher row, which canAccessPanel then rejects. This one writes all three
 * rows — user, publisher, first app — in one transaction, in the state the
 * domain expects.
 *
 * **Nothing here is approved.** The publisher is `pending` and the app is
 * `pending`; an admin approves. Open signup with a manual gate is the cheapest
 * fraud control we have (docs/00 §2), and step 2 is what the human reads.
 */
class Register extends BaseRegister
{
    /** Countries the traffic actually comes from, per the prototype. `XX` is the
     *  ISO user-assigned code for other/unknown — the column is char(2). */
    private const COUNTRIES = [
        'EG' => 'Egypt',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'DE' => 'Germany',
        'IN' => 'India',
        'BR' => 'Brazil',
        'PH' => 'Philippines',
        'ID' => 'Indonesia',
        'NG' => 'Nigeria',
        'XX' => 'Other',
    ];

    private const PLATFORMS = [
        'web' => 'Web',
        'android' => 'Android',
        'ios' => 'iOS',
        'all' => 'All',
    ];

    /** Stored as written: the answer is the value, so the admin reads prose. */
    private const TRAFFIC_TYPES = ['Rewards app', 'Website', 'GPT site', 'Mobile game', 'Community', 'Other'];

    private const VOLUMES = ['Under 1,000', '1,000 – 10,000', '10,000 – 100,000', '100,000 – 1M', 'Over 1M'];

    /**
     * `settings.signups_enabled` is the switch an admin reaches for when a
     * signup flood arrives — it closes the door to new accounts without
     * touching login or password reset for the publishers who already have one.
     */
    public function mount(): void
    {
        abort_unless(filter_var(Setting::get('signups_enabled', '1'), FILTER_VALIDATE_BOOLEAN), 404);

        parent::mount();
    }

    public function getHeading(): string
    {
        return 'Apply as a publisher';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Account')
                    ->schema([
                        Text::make('You can explore the dashboard and fire a test conversion straight away. A human reviews the account before it can earn.'),
                        TextInput::make('name')
                            ->label('Full name')
                            ->required()
                            ->maxLength(255)
                            ->autofocus(),
                        TextInput::make('email')
                            ->label('Work email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique($this->getUserModel()),
                        TextInput::make('password')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required()
                            // The prototype gates on its strength meter reaching
                            // "fair". Length is what that meter mostly measures.
                            ->rule(Password::min(10)->letters()->numbers())
                            ->helperText('At least 10 characters. Long beats complicated.')
                            ->dehydrateStateUsing(fn (#[SensitiveParameter] string $state): string => Hash::make($state)),
                        Select::make('country')
                            ->options(self::COUNTRIES)
                            ->required()
                            ->native(false),
                        TextInput::make('company_name')
                            ->label('Company')
                            ->maxLength(255),
                    ]),

                Step::make('Traffic')
                    ->schema([
                        Text::make('A person reads this before approving you. Specific answers get approved faster — it is the cheapest fraud control we have, and it is why your payouts are not funding someone\'s bot farm.'),
                        ToggleButtons::make('traffic_type')
                            ->label('Type of property')
                            ->options(array_combine(self::TRAFFIC_TYPES, self::TRAFFIC_TYPES))
                            ->required()
                            ->inline()
                            ->grouped(false),
                        Select::make('monthly_actives')
                            ->label('Monthly active users')
                            ->options(array_combine(self::VOLUMES, self::VOLUMES))
                            ->required()
                            ->native(false),
                        Textarea::make('audience')
                            ->label('Tell us about your audience')
                            ->placeholder('e.g. Rewards app for Egyptian and Gulf users, mostly Android, users earn coins for gift cards. 40k MAU, organic from TikTok.')
                            ->helperText('Geography, platform, how you acquire users, what they spend rewards on.')
                            ->required()
                            ->minLength(20)
                            ->maxLength(2000)
                            ->rows(4),
                    ]),

                Step::make('First app')
                    ->schema([
                        Text::make('This creates the app ID and secret you will embed. Everything here is editable later.'),
                        TextInput::make('app_name')
                            ->label('App name')
                            ->placeholder('Just Rewards')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),
                        TextInput::make('app_url')
                            ->label('App or site URL')
                            ->url()
                            ->placeholder('https://justrewards.app')
                            ->required()
                            ->maxLength(255),
                        ToggleButtons::make('platform')
                            ->options(self::PLATFORMS)
                            ->default('web')
                            ->required()
                            ->inline()
                            ->grouped(false),
                        TextInput::make('currency_name')
                            ->label('Currency name')
                            ->placeholder('Coins')
                            ->default('Coins')
                            ->required()
                            ->maxLength(50)
                            ->live(onBlur: true),
                        TextInput::make('currency_rate')
                            ->label('Units per $1')
                            ->numeric()
                            ->minValue(1)
                            ->default(1000)
                            ->required()
                            ->live(onBlur: true),
                        // The single line that prevents the most common setup
                        // error — a rate that credits users pennies or fortunes.
                        Text::make(fn (Get $get): string => sprintf(
                            'A $0.50 offer at your %s%% share credits your user %s %s.',
                            $this->revShare(),
                            number_format(0.5 * ($this->revShare() / 100) * (float) ($get('currency_rate') ?: 1000)),
                            $get('currency_name') ?: 'Coins',
                        )),
                    ]),

                Step::make('Review')
                    ->schema([
                        Text::make('Anything wrong? Step back and fix it.'),
                        Text::make(fn (Get $get): Htmlable => $this->reviewList($get)),
                        // Both attestations are required. A traffic-quality
                        // promise nobody read is worth nothing.
                        Checkbox::make('terms')
                            ->label('I accept the publisher agreement and traffic policy.')
                            ->accepted()
                            ->required(),
                        Checkbox::make('incentivised')
                            ->label('I confirm my traffic is incentivised and that I will not send bot, proxy, or purchased traffic.')
                            ->accepted()
                            ->required(),
                    ]),
            ])->submitAction(new HtmlString(Blade::render(
                '<x-filament::button type="submit" size="sm">Create account</x-filament::button>'
            ))),
        ]);
    }

    /**
     * The vault brand panel is the right-hand half of this page. A second
     * column of deal terms used to sit inside the form and crush the wizard.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE),
            $this->getFormContentComponent(),
            RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER),
        ]);
    }

    /** The default simple-page card is one narrow column; this one holds a wizard. */
    public function getMaxWidth(): Width
    {
        return Width::FiveExtraLarge;
    }

    /**
     * The wizard carries its own next/back/submit buttons; the page's default
     * action would sit under the last step and submit from any of them.
     *
     * @return array<never>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data) {
            // The concrete model rather than getUserModel(): this page writes a
            // user, a publisher and an app together, and the two relations only
            // exist on App\Models\User.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Publisher,
            ]);

            $publisher = $user->publisher()->create([
                'company_name' => $data['company_name'] ?? null,
                'country' => $data['country'],
                // One text column, three answers, in the order the reviewer
                // reads them. Splitting them into columns would be a migration
                // to make an admin's eyes travel further.
                'traffic_source' => "{$data['traffic_type']} · {$data['monthly_actives']} MAU\n\n{$data['audience']}",
                'status' => PublisherStatus::Pending,
                // One config value, not a column default — admins change the
                // platform default without a migration (docs/00 §2).
                'rev_share' => $this->revShare(),
            ]);

            // app_id and secret_key are generated by the model on create.
            $app = $publisher->apps()->create([
                'name' => $data['app_name'],
                'url' => $data['app_url'],
                'platform' => $data['platform'],
                'currency_name' => $data['currency_name'],
                'currency_rate' => $data['currency_rate'],
                'status' => AppStatus::Pending,
            ]);

            // The one moment the secret can be handed over. `secret_key` is a
            // shown-once value (docs/00 §8) and the only other way to see one
            // is Regenerate, which invalidates signatures the publisher may
            // already have embedded — a destructive action as step one of
            // onboarding. Put, not flash: Filament logs the user in and
            // regenerates the session between here and the redirect.
            session()->put(Welcome::SESSION_KEY, [
                'app_id' => $app->app_id,
                'secret' => (string) $app->secret_key,
                'app_name' => $app->name,
            ]);

            return $user;
        });
    }

    /**
     * Straight to the credential handover instead of the dashboard.
     *
     * Filament's RegistrationResponse redirects to the panel root, which with
     * email verification on is the verification prompt — so the secret would
     * be created, never displayed, and the publisher would have to regenerate
     * one to integrate at all.
     */
    public function register(): ?RegistrationResponse
    {
        $response = parent::register();

        // Null is the rate-limited path: the notification is already on screen
        // and nothing was created, so there is nowhere to send them.
        if ($response === null) {
            return null;
        }

        // Livewire records the redirect on the component rather than in the
        // return value, so discarding Filament's response is not "no response"
        // — it is "the response has already been set, and to somewhere else".
        $this->redirect(Welcome::getUrl());

        return null;
    }

    private function revShare(): float
    {
        return (float) Setting::get('default_rev_share', 70);
    }

    private function reviewList(Get $get): Htmlable
    {
        $rate = $get('currency_rate');
        $currency = $get('currency_name');

        $rows = [
            'Name' => $get('name'),
            'Email' => $get('email'),
            'Country' => self::COUNTRIES[$get('country')] ?? null,
            'Traffic' => implode(' · ', array_filter([$get('traffic_type'), $get('monthly_actives')])),
            'App' => $get('app_name'),
            'URL' => $get('app_url'),
            'Platform' => self::PLATFORMS[$get('platform')] ?? null,
            'Currency' => ($rate && $currency) ? number_format((float) $rate).' '.$currency.' per $1' : null,
        ];

        $html = collect($rows)->map(fn (?string $value, string $label): string => sprintf(
            '<div class="flex items-baseline justify-between gap-6 px-4 py-3"><dt class="shrink-0 text-sm text-gray-400">%s</dt><dd class="truncate text-right text-sm %s">%s</dd></div>',
            e($label),
            filled($value) ? 'text-gray-50' : 'text-gray-500',
            e(filled($value) ? $value : 'Not set'),
        ))->implode('');

        return new HtmlString('<dl class="divide-y divide-gray-600 rounded-xl border border-gray-600">'.$html.'</dl>');
    }
}
