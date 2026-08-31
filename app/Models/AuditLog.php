<?php

namespace App\Models;

use App\Enums\AuditActionFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Every admin action touching money, status, or bans lands here.
     *
     * The actor defaults to the authenticated user, so a call site inside a
     * Filament action needs to pass nothing; a queued job has to be explicit
     * because there is no session to read.
     *
     * @param  ?array<string, mixed>  $before
     * @param  ?array<string, mixed>  $after
     */
    public static function record(
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): ?self {
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        // This table is the record of what a *person* did (docs/01 §8), and
        // `user_id` is not nullable. The same services also run from queue
        // workers and console commands, where there is nobody to attribute the
        // action to — those belong in the application log, not here.
        if ($actor === null) {
            return null;
        }

        return self::create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function family(): AuditActionFamily
    {
        return AuditActionFamily::fromAction($this->action);
    }

    public function label(): string
    {
        return Str::ucfirst(str_replace(['.', '_'], ' ', $this->action));
    }

    public function subjectLabel(): string
    {
        return class_basename($this->subject_type).' #'.$this->subject_id;
    }

    /**
     * Keys that actually moved, before on the left and after on the right.
     *
     * A one-sided payload (create, reveal, a reason-only block) still appears
     * as a row — the empty side renders as an em dash in the table.
     *
     * @return list<array{key: string, before: mixed, after: mixed}>
     */
    public function changes(): array
    {
        $before = $this->before ?? [];
        $after = $this->after ?? [];
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

        return array_map(fn (string $key): array => [
            'key' => $key,
            'before' => $before[$key] ?? null,
            'after' => $after[$key] ?? null,
        ], $keys);
    }

    public static function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $string = is_scalar($value) ? (string) $value : '';

        return $string === '' ? '—' : $string;
    }

    /** @param  Builder<self>  $query */
    public function scopeFamily(Builder $query, AuditActionFamily $family): Builder
    {
        return $family->constrain($query);
    }
}
