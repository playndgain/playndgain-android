<?php

namespace App\Filament\Publisher\Pages;

use App\Enums\PaymentMethodType;
use App\Enums\PayoutStatementStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Auth\Register;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Support\PaymentMethodFields;
use App\Filament\Publisher\Widgets\PaymentsSummary;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\PayoutStatement;
use App\Services\Money\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Payments — docs/08 §10.
 *
 * One page for everything money: the five cards, each payout destination
 * edited in place, the billing address a credit note prints, and the monthly
 * statements with a detail modal per row. Crypto, PayPal and bank transfer
 * are offered; Wise survives in the enum for the history of payouts already
 * made. There is no "request" button — payouts open themselves on the 1st.
 *
 * @property-read bool $canWrite Livewire computed via getCanWriteProperty().
 */
class Payments extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'Payouts';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Payments';

    protected static ?string $slug = 'payments';

    protected string $view = 'filament.publisher.pages.payments';

    /**
     * An amber mark on the sidebar for as long as nothing is set to receive
     * the monthly payout — the one state on this page worth chasing from
     * elsewhere in the panel.
     */
    public static function getNavigationBadge(): ?string
    {
        return self::whatIsMissing() === null ? null : '!';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return self::whatIsMissing();
    }

    /** What still stands between this publisher and a payout, if anything. */
    public static function whatIsMissing(): ?string
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null) {
            return null;
        }

        foreach (self::BILLING_REQUIRED as $field) {
            if (blank($publisher->{$field})) {
                return 'Billing details incomplete';
            }
        }

        return PaymentMethod::query()
            ->where('publisher_id', $publisher->id)
            ->where('is_default', true)
            ->exists() ? null : 'No payout method set';
    }

    public function getSubheading(): ?string
    {
        return 'Manage your payment methods and view payment history';
    }

    protected function getHeaderWidgets(): array
    {
        return [PaymentsSummary::class];
    }

    /** The destination types the page offers. */
    public const OFFERED = [
        PaymentMethodType::Crypto,
        PaymentMethodType::Paypal,
        PaymentMethodType::Bank,
    ];

    /**
     * Null when the row was encrypted under a key this environment does not
     * have (see PaymentMethod::fromEncryptedString) — an empty card the
     * publisher fills in again, not a 500.
     *
     * @return array<string, mixed>
     */
    private function detailsOf(?PaymentMethod $method): array
    {
        $details = $method?->details;

        return is_array($details) ? $details : [];
    }

    /**
     * The address block a credit note prints — the columns existed since the
     * billing-fields migration with nowhere to edit them.
     */
    private function billingForm(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('company_name')->label('Company or full name')->required()->maxLength(255),
                Select::make('country')->label('Country')->options(Register::COUNTRIES)->required()->native(false),
                TextInput::make('billing_address')->label('Address')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('billing_city')->label('City')->required()->maxLength(255),
                TextInput::make('billing_postal_code')->label('Postal code')->required()->maxLength(32),
                TextInput::make('vat_number')->label('VAT number')->maxLength(64)
                    ->helperText('Optional. Printed on your credit note when set.'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function billingState(): array
    {
        $publisher = CurrentPublisher::get();

        return $publisher === null ? [] : [
            'company_name' => $publisher->company_name,
            'billing_address' => $publisher->billing_address,
            'billing_city' => $publisher->billing_city,
            'billing_postal_code' => $publisher->billing_postal_code,
            'country' => $publisher->country,
            'vat_number' => $publisher->vat_number,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function saveBilling(array $data): void
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null || ! $this->canWrite) {
            return;
        }

        $publisher->update($data);
        AuditLog::record('publisher.billing_updated', $publisher);

        Notification::make()->title('Billing details saved')->send();
    }

    /**
     * The strip above the cards: where the next payout goes, or that it has
     * nowhere to go yet. `legacy` is a destination of a type no longer offered
     * that still holds the Active flag.
     *
     * @return array{ready: bool, method: ?string, legacy: bool, billing: bool}
     */
    public function getPayoutStateProperty(): array
    {
        $active = $this->activeType();
        $billing = $this->billingComplete();

        return [
            'ready' => $active !== null && $billing,
            'method' => $active === null ? null : PaymentMethodFields::label($active),
            'legacy' => $active !== null && ! in_array($active, self::OFFERED, true),
            'billing' => $billing,
        ];
    }

    /**
     * The two rows the page shows: where the payout goes, and the address the
     * credit note prints. Both open a modal; neither edits in place.
     *
     * @return array{summary: string, saved: bool, active: bool}
     */
    public function getMethodRowProperty(): array
    {
        $type = $this->activeType() ?? $this->savedTypes()->keys()
            ->map(fn (string $value): PaymentMethodType => PaymentMethodType::from($value))
            ->first();

        if ($type === null) {
            return ['summary' => 'Not set up', 'saved' => false, 'active' => false];
        }

        $method = $this->methodOf($type);

        return [
            'summary' => PaymentMethodFields::label($type).' · '
                .PaymentMethodFields::summary($type, $this->detailsOf($method)),
            'saved' => $method !== null,
            'active' => $method?->is_default === true,
        ];
    }

    /** @return array{complete: bool, summary: string} */
    public function getBillingRowProperty(): array
    {
        $state = array_filter($this->billingState(), fn (mixed $value): bool => filled($value));

        return [
            'complete' => $this->billingComplete(),
            'summary' => $this->billingComplete()
                ? implode(', ', array_filter([
                    $state['company_name'] ?? null,
                    $state['billing_city'] ?? null,
                    Register::COUNTRIES[$state['country'] ?? ''] ?? null,
                ]))
                : 'Required before your payouts can be sent',
        ];
    }

    /**
     * One modal for every destination: pick the method, fill that method's
     * fields. The fields, and so the validation, are still PaymentMethodFields
     * — only the trigger and the type select are new.
     */
    public function editMethodAction(): Action
    {
        return Action::make('editMethod')
            ->modalHeading('Payment method')
            ->modalDescription('Pick where your monthly payout should be sent.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Save')
            ->schema([
                Radio::make('type')
                    ->label('Method')
                    ->options(fn (): array => self::typeOptions())
                    ->descriptions(fn (): array => $this->typeDescriptions())
                    ->default(self::OFFERED[0]->value)
                    ->required()
                    ->live(),
                ...array_map(
                    // Only the picked type is visible, and Filament neither
                    // validates nor dehydrates what is hidden — so one schema
                    // holds all three without them fighting over `details.*`.
                    fn (PaymentMethodType $type): Group => Group::make(PaymentMethodFields::for($type))
                        ->columns(2)
                        ->visible(fn (Get $get): bool => $get('type') === $type->value),
                    self::OFFERED,
                ),
            ])
            ->fillForm(fn (array $arguments): array => $this->methodFormState($arguments))
            // A second submit button, so "make default" saves the same form
            // state rather than a stale copy of it.
            ->extraModalFooterActions(fn (Action $action): array => array_values(array_filter([
                ! $this->canWrite ? null : $action
                    ->makeModalSubmitAction('saveAndActivate', ['activate' => true])
                    ->label('Save and make default')
                    ->icon('heroicon-o-check-badge'),
                ! $this->canWrite || $this->savedTypes()->isEmpty() ? null : Action::make('removeMethod')
                    ->label('Remove this method')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->link()
                    ->requiresConfirmation()
                    ->modalDescription('A payout already requested keeps the destination it was requested with.')
                    ->action(function (): void {
                        $type = (string) ($this->mountedActions[0]['data']['type'] ?? '');

                        if ($type !== '') {
                            $this->remove($type);
                        }

                        $this->unmountAction();
                    }),
            ])))
            // A suspended account reads its destinations and changes nothing.
            ->modalSubmitAction($this->canWrite ? null : false)
            ->action(function (array $arguments, array $data): void {
                $this->save(
                    (string) ($data['type'] ?? ''),
                    (array) ($data['details'] ?? []),
                    (bool) ($arguments['activate'] ?? false),
                );
            });
    }

    /**
     * The modal opens on the destination that is already paid to, or on the
     * type the tile was asked for.
     *
     * @return array<string, mixed>
     */
    private function methodFormState(array $arguments): array
    {
        $type = isset($arguments['type'])
            ? PaymentMethodType::from((string) $arguments['type'])
            : ($this->activeType() ?? self::OFFERED[0]);

        if (! in_array($type, self::OFFERED, true)) {
            $type = self::OFFERED[0];
        }

        return [
            'type' => $type->value,
            'details' => $this->detailsOf($this->methodOf($type)),
        ];
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        return array_reduce(
            self::OFFERED,
            fn (array $carry, PaymentMethodType $type): array => $carry + [
                $type->value => PaymentMethodFields::label($type),
            ],
            [],
        );
    }

    /** @return array<string, string> */
    private function typeDescriptions(): array
    {
        $saved = $this->savedTypes();

        return array_reduce(
            self::OFFERED,
            fn (array $carry, PaymentMethodType $type): array => $carry + [
                $type->value => PaymentMethodFields::description($type)
                    .($saved->has($type->value) ? ' · Saved' : ''),
            ],
            [],
        );
    }

    /** The types this publisher already has details for. */
    private function savedTypes(): Collection
    {
        return PaymentMethod::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->whereIn('type', self::offeredValues())
            ->pluck('is_default', 'type');
    }

    /** The address block a credit note prints, behind its own row. */
    public function editBillingAction(): Action
    {
        return Action::make('editBilling')
            ->modalHeading('Billing details')
            ->modalDescription('Your address is printed on the credit note that comes with every payout. Nothing can be paid out, and no payment method can be set up, until this is complete.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Save')
            ->schema(fn (Schema $schema): Schema => $this->billingForm($schema))
            ->fillForm(fn (): array => $this->billingState())
            ->modalSubmitAction($this->canWrite ? null : false)
            ->action(fn (array $data) => $this->saveBilling($data));
    }

    /**
     * The rows call these rather than mounting the action from the markup.
     * The billing gate is enforced here too, not only in the markup: a stale
     * page or a hand-made request has to hit the same wall a click does.
     */
    public function openMethod(?string $type = null): void
    {
        if (! $this->billingComplete()) {
            Notification::make()
                ->title('Billing details first')
                ->body('Your address goes on the credit note that comes with every payout, so it has to be complete before a payment method can be set up.')
                ->warning()
                ->send();

            $this->openBilling();

            return;
        }

        $this->mountAction('editMethod', $type === null
            ? []
            : ['type' => PaymentMethodType::from($type)->value]);
    }

    public function openBilling(): void
    {
        $this->mountAction('editBilling');
    }

    private function activeType(): ?PaymentMethodType
    {
        $active = PaymentMethod::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->where('is_default', true)
            ->value('type');

        return $active === null || $active instanceof PaymentMethodType
            ? $active
            : PaymentMethodType::from((string) $active);
    }

    /** @return list<string> */
    private static function offeredValues(): array
    {
        return array_map(fn (PaymentMethodType $type): string => $type->value, self::OFFERED);
    }

    /**
     * The address a credit note cannot be issued without. A payout with no
     * billing record behind it is a payout nobody can account for, so this
     * gates which destination gets the money, not whether details can be saved.
     */
    public const BILLING_REQUIRED = [
        'company_name',
        'country',
        'billing_address',
        'billing_city',
        'billing_postal_code',
    ];

    public function billingComplete(): bool
    {
        $state = $this->billingState();

        foreach (self::BILLING_REQUIRED as $field) {
            if (blank($state[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** A suspended account is read-only — docs/08 §1, enforced by the policy. */
    public function getCanWriteProperty(): bool
    {
        return Gate::allows('create', PaymentMethod::class);
    }

    /** @param  array<string, mixed>  $details */
    public function save(string $type, array $details = [], bool $activate = false): void
    {
        $type = PaymentMethodType::from($type);
        $publisher = CurrentPublisher::get();

        if ($publisher === null || ! in_array($type, self::OFFERED, true)) {
            return;
        }

        $details = array_filter($details, fn (mixed $value): bool => filled($value));
        $method = $this->methodOf($type);

        if ($method !== null) {
            Gate::authorize('update', $method);

            $method->update(['details' => $details]);
            AuditLog::record('payment_method.updated', $method);
        } else {
            Gate::authorize('create', PaymentMethod::class);

            // A newly saved destination becomes the default unless another
            // offered one already is: one destination and no default is a
            // payout with nowhere to go, and a legacy Wise row must not keep
            // the flag over a destination the publisher can actually see.
            // Incomplete billing blocks that too — same rule as activate().
            $offeredActive = ! $this->billingComplete() || PaymentMethod::query()
                ->where('publisher_id', $publisher->id)
                ->where('is_default', true)
                ->whereIn('type', self::offeredValues())
                ->exists();

            if (! $offeredActive) {
                PaymentMethod::query()->where('publisher_id', $publisher->id)->update(['is_default' => false]);
            }

            // One row per (publisher, type) is a unique key, so a double
            // submit lands on the same row instead of a second wallet.
            $method = PaymentMethod::query()->updateOrCreate(
                ['publisher_id' => $publisher->id, 'type' => $type],
                ['details' => $details, 'is_default' => ! $offeredActive],
            );
            AuditLog::record('payment_method.created', $method);
        }

        Notification::make()
            ->title(PaymentMethodFields::label($type).' saved')
            ->body('A payout already requested keeps the destination it was requested with.')
            ->send();

        if ($activate && ! $method->refresh()->is_default) {
            $this->activate($type->value);
        }
    }

    public function activate(string $type): void
    {
        $type = PaymentMethodType::from($type);
        $method = $this->methodOf($type);

        if ($method === null) {
            Notification::make()->title('Save the details first')->send();

            return;
        }

        // Nothing is paid to a destination we cannot put an address on.
        if (! $this->billingComplete()) {
            Notification::make()
                ->title('Billing details first')
                ->body('Your address goes on the credit note that comes with the payout, so it has to be complete before a method can be your default.')
                ->warning()
                ->send();

            // Called from inside the method modal as often as from the row, so
            // the billing modal takes its place rather than opening under it.
            filled($this->mountedActions)
                ? $this->replaceMountedAction('editBilling')
                : $this->openBilling();

            return;
        }

        Gate::authorize('update', $method);

        PaymentMethod::query()
            ->where('publisher_id', $method->publisher_id)
            ->whereKeyNot($method->id)
            ->update(['is_default' => false]);

        $method->update(['is_default' => true]);
        AuditLog::record('payment_method.default_changed', $method);

        Notification::make()
            ->title(PaymentMethodFields::label($type).' is now active')
            ->body('The monthly payout goes here from the next run.')
            ->send();
    }

    public function remove(string $type): void
    {
        $type = PaymentMethodType::from($type);
        $method = $this->methodOf($type);

        if ($method === null) {
            return;
        }

        Gate::authorize('delete', $method);

        $wasDefault = $method->is_default;
        $publisherId = $method->publisher_id;

        $method->delete();
        AuditLog::record('payment_method.deleted', $method);

        if ($wasDefault) {
            PaymentMethod::query()
                ->where('publisher_id', $publisherId)
                ->latest('id')
                ->first()
                ?->update(['is_default' => true]);
        }

        Notification::make()->title(PaymentMethodFields::label($type).' removed')->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PayoutStatement::query()
                ->where('publisher_id', CurrentPublisher::id())
                ->with('withdrawal'))
            ->columns([
                TextColumn::make('period')
                    ->label('Period')
                    ->formatStateUsing(fn (PayoutStatement $record): string => $record->periodLabel())
                    ->sortable(),
                TextColumn::make('due_date')->label('Due date')->date('M j, Y'),
                TextColumn::make('gross')
                    ->label('Payment amount')
                    ->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (string $state): string => self::dollars($state))
                    ->alignEnd(),
                TextColumn::make('chargebacks')
                    ->label('Chargeback')
                    ->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (string $state): string => self::dollars($state))
                    ->color(fn (string $state): string => bccomp($state, '0', Money::SCALE) < 0 ? 'loss' : 'gray')
                    ->alignEnd(),
                TextColumn::make('net')
                    ->label('Actual payment')
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::SemiBold)
                    ->formatStateUsing(fn (string $state): string => self::dollars($state))
                    ->color(fn (string $state): string => match (bccomp($state, '0', Money::SCALE)) {
                        1 => 'gain',
                        -1 => 'loss',
                        default => 'gray',
                    })
                    ->alignEnd(),
                ViewColumn::make('status')->view('filament.tables.columns.status-pill'),
                TextColumn::make('withdrawal.method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethodType $state): string => PaymentMethodFields::label($state))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(PayoutStatementStatus::class),
            ])
            ->recordActions([
                $this->viewStatementAction(),
            ])
            ->defaultSort('period', 'desc')
            ->paginated([12, 24])
            ->emptyStateHeading('No statements yet')
            ->emptyStateDescription('Your first statement is generated on the 1st of next month.');
    }

    /**
     * Gemiad-shaped: three tiles, the breakdown, and — once a payout exists —
     * where it went and its reference. A pending month has no destination yet.
     */
    private function viewStatementAction(): Action
    {
        return Action::make('view')
            ->label('View')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading('Payment details')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Section::make()->columns(3)->schema([
                    ViewEntry::make('status')->label('Status')->view('filament.infolists.entries.status-pill'),
                    TextEntry::make('period')
                        ->label('Period')
                        ->formatStateUsing(fn (PayoutStatement $record): string => $record->periodLabel()),
                    TextEntry::make('due_date')->label('Due date')->date('M j, Y'),
                ]),

                Section::make('Payment breakdown')->columns(2)->schema([
                    TextEntry::make('gross')
                        ->label('Earnings')
                        ->fontFamily(FontFamily::Mono)
                        ->formatStateUsing(fn (string $state): string => self::dollars($state)),
                    TextEntry::make('chargebacks')
                        ->label('Chargebacks')
                        ->fontFamily(FontFamily::Mono)
                        ->color(fn (string $state): ?string => bccomp($state, '0', Money::SCALE) < 0 ? 'loss' : null)
                        ->formatStateUsing(fn (string $state): string => self::dollars($state)),
                    TextEntry::make('adjustments')
                        ->label('Adjustments')
                        ->fontFamily(FontFamily::Mono)
                        ->formatStateUsing(fn (string $state): string => self::dollars($state))
                        ->visible(fn (PayoutStatement $record): bool => bccomp($record->adjustments, '0', Money::SCALE) !== 0),
                    TextEntry::make('carried_in')
                        ->label('Carried from earlier periods')
                        ->fontFamily(FontFamily::Mono)
                        ->state(fn (PayoutStatement $record): ?string => $record->carriedIn())
                        ->formatStateUsing(fn (string $state): string => self::dollars($state))
                        ->visible(fn (PayoutStatement $record): bool => $record->carriedIn() !== null),
                    TextEntry::make('net')
                        ->label('Net payment')
                        ->fontFamily(FontFamily::Mono)
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color(fn (string $state): ?string => match (bccomp($state, '0', Money::SCALE)) {
                            1 => 'gain',
                            -1 => 'loss',
                            default => null,
                        })
                        ->formatStateUsing(fn (string $state): string => self::dollars($state))
                        ->columnSpanFull(),
                    TextEntry::make('note')
                        ->label('Note')
                        ->columnSpanFull()
                        ->visible(fn (PayoutStatement $record): bool => filled($record->note)),
                ]),

                Section::make('Payment method')->columns(2)
                    ->visible(fn (PayoutStatement $record): bool => $record->withdrawal !== null)
                    ->schema([
                        TextEntry::make('withdrawal.method')
                            ->label('Method')
                            ->badge()
                            ->formatStateUsing(fn (PaymentMethodType $state): string => PaymentMethodFields::label($state)),
                        TextEntry::make('withdrawal.status')
                            ->label('Payout status')
                            ->badge()
                            ->formatStateUsing(fn (WithdrawalStatus $state): string => ucfirst($state->value)),

                        TextEntry::make('crypto_asset')
                            ->label('Currency')
                            ->state(fn (PayoutStatement $record): ?string => $record->withdrawal?->destination()['asset'] ?? null)
                            ->visible(fn (PayoutStatement $record): bool => $record->withdrawal?->method === PaymentMethodType::Crypto),
                        TextEntry::make('crypto_network')
                            ->label('Network')
                            ->state(fn (PayoutStatement $record): ?string => PaymentMethodFields::CRYPTO_NETWORKS[$record->withdrawal?->destination()['network'] ?? ''] ?? ($record->withdrawal?->destination()['network'] ?? null))
                            ->visible(fn (PayoutStatement $record): bool => $record->withdrawal?->method === PaymentMethodType::Crypto),
                        TextEntry::make('crypto_address')
                            ->label('Wallet address')
                            ->fontFamily(FontFamily::Mono)
                            ->state(fn (PayoutStatement $record): ?string => $record->withdrawal?->destination()['address'] ?? null)
                            ->copyable()
                            ->columnSpanFull()
                            ->visible(fn (PayoutStatement $record): bool => $record->withdrawal?->method === PaymentMethodType::Crypto),

                        TextEntry::make('email_destination')
                            ->label(fn (PayoutStatement $record): string => $record->withdrawal?->method === PaymentMethodType::Wise ? 'Wise email' : 'PayPal email')
                            ->state(fn (PayoutStatement $record): ?string => $record->withdrawal?->destination()['email'] ?? null)
                            ->copyable()
                            ->columnSpanFull()
                            ->visible(fn (PayoutStatement $record): bool => in_array($record->withdrawal?->method, [PaymentMethodType::Paypal, PaymentMethodType::Wise], true)),

                        TextEntry::make('bank_account_name')
                            ->label('Account holder')
                            ->state(fn (PayoutStatement $record): ?string => $record->withdrawal?->destination()['account_name'] ?? null)
                            ->visible(fn (PayoutStatement $record): bool => $record->withdrawal?->method === PaymentMethodType::Bank),
                        TextEntry::make('bank_iban')
                            ->label('IBAN / account number')
                            ->fontFamily(FontFamily::Mono)
                            ->state(fn (PayoutStatement $record): ?string => $record->withdrawal?->destination()['iban'] ?? null)
                            ->copyable()
                            ->visible(fn (PayoutStatement $record): bool => $record->withdrawal?->method === PaymentMethodType::Bank),
                        TextEntry::make('bank_swift')
                            ->label('SWIFT / BIC')
                            ->state(fn (PayoutStatement $record): ?string => $record->withdrawal?->destination()['swift'] ?? null)
                            ->placeholder('—')
                            ->visible(fn (PayoutStatement $record): bool => $record->withdrawal?->method === PaymentMethodType::Bank),

                        TextEntry::make('withdrawal.tx_reference')
                            ->label('Transaction ID')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('withdrawal.paid_at')
                            ->label('Paid on')
                            ->dateTime('M j, Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('settled_with')
                            ->label('Settled together with')
                            ->state(fn (PayoutStatement $record): ?string => $this->settledWith($record))
                            ->visible(fn (PayoutStatement $record): bool => $this->settledWith($record) !== null),
                    ]),
            ]);
    }

    /** "Jul, 2026 and Jun, 2026" — the other months the same payout covered. */
    private function settledWith(PayoutStatement $record): ?string
    {
        if ($record->withdrawal_id === null) {
            return null;
        }

        $others = PayoutStatement::query()
            ->where('withdrawal_id', $record->withdrawal_id)
            ->whereKeyNot($record->id)
            ->orderByDesc('period')
            ->get()
            ->map(fn (PayoutStatement $statement): string => $statement->periodLabel());

        return $others->isEmpty() ? null : $others->implode(', ');
    }

    /** One formatter for every figure on the page — design.md §3. */
    public static function dollars(string $amount): string
    {
        return Money::display($amount);
    }

    private function methodOf(PaymentMethodType $type): ?PaymentMethod
    {
        // Unique per (publisher, type) since the dedupe migration.
        return PaymentMethod::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->where('type', $type)
            ->first();
    }
}
