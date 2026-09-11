<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * How an audit row is grouped in the admin table.
 *
 * Actions are stored as `domain.verb`. Family is derived, not stored, so a new
 * `AuditLog::record()` call is classified without a migration. Precedence is
 * access, then money, then settings, then status — `conversion.approved` is
 * money, `publisher.approved` is status.
 */
enum AuditActionFamily: string implements HasColor, HasIcon, HasLabel
{
    case Money = 'money';
    case Access = 'access';
    case Status = 'status';
    case Settings = 'settings';
    case Catalog = 'catalog';

    public function getLabel(): string
    {
        return match ($this) {
            self::Money => 'Money',
            self::Access => 'Access',
            self::Status => 'Status',
            self::Settings => 'Settings',
            self::Catalog => 'Catalog',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Money => 'success',
            self::Access => 'warning',
            self::Status => 'info',
            self::Settings => 'gray',
            self::Catalog => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Money => Heroicon::OutlinedBanknotes,
            self::Access => Heroicon::OutlinedKey,
            self::Status => Heroicon::OutlinedArrowPath,
            self::Settings => Heroicon::OutlinedCog6Tooth,
            self::Catalog => Heroicon::OutlinedRectangleStack,
        };
    }

    public static function fromAction(string $action): self
    {
        foreach ([self::Access, self::Money, self::Settings, self::Status] as $family) {
            if ($family->matches($action)) {
                return $family;
            }
        }

        return self::Catalog;
    }

    public function matches(string $action): bool
    {
        foreach ($this->patterns() as $pattern) {
            if (Str::is($pattern, $action)) {
                return true;
            }
        }

        return false;
    }

    public function constrain(Builder $query): Builder
    {
        return match ($this) {
            self::Catalog => $query->whereNot(function (Builder $query): void {
                $query
                    ->where(fn (Builder $inner): Builder => self::Access->constrainByPatterns($inner))
                    ->orWhere(fn (Builder $inner): Builder => self::Money->constrainByPatterns($inner))
                    ->orWhere(fn (Builder $inner): Builder => self::Settings->constrainByPatterns($inner))
                    ->orWhere(fn (Builder $inner): Builder => self::Status->constrainByPatterns($inner));
            }),
            self::Status => $this->constrainByPatterns($query)
                ->whereNot(function (Builder $query): void {
                    $query
                        ->where(fn (Builder $inner): Builder => self::Access->constrainByPatterns($inner))
                        ->orWhere(fn (Builder $inner): Builder => self::Money->constrainByPatterns($inner))
                        ->orWhere(fn (Builder $inner): Builder => self::Settings->constrainByPatterns($inner));
                }),
            default => $this->constrainByPatterns($query),
        };
    }

    /**
     * Badge colour for the verb, independent of family.
     *
     * Approving a publisher and approving a conversion are different families
     * and the same colour — the verb is what an operator scans for.
     */
    public static function verbColor(string $action): string
    {
        return match (Str::afterLast($action, '.')) {
            'approved', 'paid', 'unblocked', 'unbanned', 'email_verified' => 'success',
            'rejected', 'banned', 'blocked', 'force_reversed', 'ban_made_permanent', 'deleted' => 'danger',
            'impersonated', 'impersonation_ended', 'revealed', 'credentials_revealed',
            'secret_regenerated', '2fa_reset', 'password_changed' => 'warning',
            'balance_adjusted', 'requested' => 'primary',
            default => 'gray',
        };
    }

    public static function verbIcon(string $action): Heroicon
    {
        return match (Str::afterLast($action, '.')) {
            'approved', 'paid', 'unblocked', 'unbanned', 'email_verified' => Heroicon::OutlinedCheckCircle,
            'rejected', 'banned', 'blocked', 'force_reversed', 'ban_made_permanent', 'deleted' => Heroicon::OutlinedNoSymbol,
            'impersonated', 'impersonation_ended' => Heroicon::OutlinedUserCircle,
            'revealed', 'credentials_revealed', 'secret_regenerated', '2fa_reset',
            'password_changed' => Heroicon::OutlinedKey,
            'balance_adjusted', 'requested' => Heroicon::OutlinedBanknotes,
            'updated', 'toggled' => Heroicon::OutlinedPencilSquare,
            'created', 'matched' => Heroicon::OutlinedPlusCircle,
            default => Heroicon::OutlinedClipboardDocumentList,
        };
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return match ($this) {
            self::Access => [
                '*.impersonated',
                '*.impersonation_ended',
                '*.revealed',
                '*.credentials_revealed',
                '*.secret_regenerated',
                '*.2fa_reset',
                '*.password_changed',
                '*.email_verified',
            ],
            self::Money => [
                'withdrawal.*',
                'conversion.*',
                'publisher.balance_adjusted',
            ],
            self::Settings => [
                'settings.*',
                'fraud_rule.*',
                'ip_provider.*',
            ],
            self::Status => [
                '*.approved',
                '*.rejected',
                '*.suspended',
                '*.banned',
                '*.blocked',
                '*.unblocked',
                '*.unbanned',
                '*.ban_made_permanent',
                '*.status',
                '*.paid',
            ],
            self::Catalog => ['*'],
        };
    }

    private function constrainByPatterns(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach ($this->patterns() as $pattern) {
                $query->orWhere('action', 'like', str_replace('*', '%', $pattern));
            }
        });
    }
}
