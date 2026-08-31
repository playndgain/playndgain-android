<?php

namespace App\Filament\Admin\Pages;

use App\Enums\NetworkStatus;
use App\Enums\OutgoingPostbackStatus;
use App\Fraud\MaxMindIpIntelligence;
use App\Models\Network;
use App\Models\OutgoingPostback;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * One page answering "is anything broken right now" — docs/07 §9.
 *
 * Green or red, no prose. Every check runs on page load rather than reading a
 * cached status, because a cached health check is a health check that can be
 * green while the thing it describes is down.
 */
class Health extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.health';

    /** docs/11 §7 alerts at 80%. Same number here so the page and the pager agree. */
    private const DISK_ALERT_PERCENT = 80.0;

    /**
     * `backup:run` is nightly at 02:00 (docs/11 §4), so 24 hours is the normal
     * gap and a threshold there would go red on a schedule. 48 gives one missed
     * run of grace — long enough not to cry wolf, short enough that two failed
     * nights are never a surprise.
     */
    private const BACKUP_MAX_AGE_HOURS = 48;

    /**
     * Both rows are read twice — once for the value, once for the verdict — and
     * both readings touch the filesystem. Memoised so a page load does not ask
     * the object store for the same listing twice, and so the two halves of a
     * row can never disagree.
     *
     * @var array{value: string, ok: bool}|null
     */
    private ?array $disk = null;

    /** @var array{value: string, ok: bool}|null */
    private ?array $backup = null;

    /**
     * @return list<array{label: string, value: string, ok: bool}>
     */
    public function getChecksProperty(): array
    {
        return [
            $this->check('MySQL', fn (): string => $this->mysql()),
            $this->check('Redis', fn (): string => $this->redis()),
            $this->check('Failed jobs (24h)', fn (): string => $this->failedJobs(), fn (string $v): bool => $v === '0'),
            $this->check('Queue depth', fn (): string => $this->queueDepth()),
            $this->check('Networks in error', fn (): string => (string) Network::query()
                ->where('status', NetworkStatus::Error)
                ->count(), fn (string $v): bool => $v === '0'),
            $this->check('Stale syncs', fn (): string => $this->staleSyncs(), fn (string $v): bool => $v === '0'),
            $this->check('Postback failure rate (24h)', fn (): string => $this->postbackFailureRate(), fn (string $v): bool => (float) $v < 5.0),
            $this->check('GeoIP databases', fn (): string => $this->geoip(), fn (string $v): bool => ! str_starts_with($v, 'missing')),
            $this->check('Disk usage', fn (): string => $this->disk()['value'], fn (): bool => $this->disk()['ok']),
            $this->check('Last backup', fn (): string => $this->backup()['value'], fn (): bool => $this->backup()['ok']),
        ];
    }

    /**
     * @param  callable(): string  $probe
     * @param  ?callable(string): bool  $isOk
     * @return array{label: string, value: string, ok: bool}
     */
    private function check(string $label, callable $probe, ?callable $isOk = null): array
    {
        try {
            $value = $probe();
        } catch (Throwable $e) {
            return ['label' => $label, 'value' => $e->getMessage(), 'ok' => false];
        }

        return [
            'label' => $label,
            'value' => $value,
            'ok' => $isOk === null ? $value !== 'down' : $isOk($value),
        ];
    }

    private function mysql(): string
    {
        DB::select('SELECT 1');

        return 'connected';
    }

    private function redis(): string
    {
        Redis::connection()->ping();

        return 'connected';
    }

    private function failedJobs(): string
    {
        return (string) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->count();
    }

    /** Depth per queue. The database driver is the only one this can read directly. */
    private function queueDepth(): string
    {
        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) AS depth')
            ->groupBy('queue')
            ->pluck('depth', 'queue');

        return $rows->isEmpty()
            ? 'empty'
            : $rows->map(fn (int $depth, string $queue): string => "{$queue}: {$depth}")->implode(' · ');
    }

    /** A network past twice its own interval has effectively stopped syncing. */
    private function staleSyncs(): string
    {
        $stale = Network::query()
            ->where('status', NetworkStatus::Active)
            ->get()
            ->filter(fn (Network $network): bool => $network->last_synced_at === null
                || $network->last_synced_at->addMinutes(2 * (int) $network->sync_interval_minutes)->isPast())
            ->count();

        return (string) $stale;
    }

    /**
     * Present and how old, per database. Age is reported without a threshold:
     * GeoLite2 republishes twice a week and IP2Proxy LITE monthly, so no single
     * number is "stale" for all three, and inventing one would go red on a
     * schedule rather than on a problem.
     */
    private function geoip(): string
    {
        $present = [];
        $missing = [];

        foreach (MaxMindIpIntelligence::DATABASES as $label => $file) {
            $path = MaxMindIpIntelligence::file($file);
            $modified = is_file($path) ? filemtime($path) : false;

            if ($modified === false) {
                $missing[] = $label;

                continue;
            }

            $present[] = $label.' '.intdiv(time() - $modified, 86400).'d';
        }

        return $missing === []
            ? implode(' · ', $present)
            : 'missing: '.implode(', ', $missing).($present === [] ? '' : ' · '.implode(' · ', $present));
    }

    /**
     * The partition the application lives on. Not `storage_path()` — on this
     * box they are the same disk, and asking about the one that also holds
     * vendor/, the release, and the MySQL data directory is the question the
     * 80% alert is really about.
     *
     * @return array{value: string, ok: bool}
     */
    private function disk(): array
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $total = disk_total_space(base_path());
        $free = disk_free_space(base_path());

        // Both return false on an unreadable path — reported rather than
        // divided by, because a health page that fatals is not a health page.
        if ($total === false || $free === false || $total <= 0.0) {
            return $this->disk = ['value' => 'unreadable', 'ok' => false];
        }

        $used = ($total - $free) / $total * 100;

        return $this->disk = [
            'value' => number_format($used, 1).'% used · '.Number::fileSize($free, precision: 1).' free',
            'ok' => $used < self::DISK_ALERT_PERCENT,
        ];
    }

    /**
     * Last backup, read from wherever spatie/laravel-backup has been told to
     * put them.
     *
     * That package is the spec's choice (docs/11 §6) and is not installed yet,
     * so `config('backup.*')` is null and this row says so. What it no longer
     * does is say so unconditionally: the moment the package is installed and
     * pointed at a destination, this reads that destination for real. The
     * difference matters because a row hardcoded to "not configured" is a red
     * light nobody looks at, and the first thing you stop believing about a
     * health page is the row that has always been red.
     *
     * ponytail: one metadata call per file in the destination. Fine against a
     * 30-day retention; if the destination ever holds thousands of objects,
     * read the newest by name (`Y-m-d-H-i-s.zip` sorts chronologically) instead.
     *
     * @return array{value: string, ok: bool}
     */
    private function backup(): array
    {
        if ($this->backup !== null) {
            return $this->backup;
        }

        $name = config('backup.backup.name');
        $disks = config('backup.backup.destination.disks');
        $disk = is_array($disks) ? ($disks[0] ?? null) : null;

        if (! is_string($name) || ! is_string($disk)) {
            return $this->backup = ['value' => 'not configured', 'ok' => false];
        }

        $filesystem = Storage::disk($disk);
        $newest = 0;

        foreach ($filesystem->files($name) as $file) {
            $newest = max($newest, $filesystem->lastModified($file));
        }

        // Configured, reachable, and empty. A different problem from "not
        // configured", and a worse one — somebody believes this is running.
        if ($newest === 0) {
            return $this->backup = ['value' => "none in {$disk}:{$name}", 'ok' => false];
        }

        $hours = intdiv(time() - $newest, 3600);

        return $this->backup = [
            'value' => $hours.'h ago',
            'ok' => $hours <= self::BACKUP_MAX_AGE_HOURS,
        ];
    }

    private function postbackFailureRate(): string
    {
        $total = OutgoingPostback::query()->where('created_at', '>=', now()->subDay())->count();

        if ($total === 0) {
            return '0';
        }

        $failed = OutgoingPostback::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', [OutgoingPostbackStatus::Failed, OutgoingPostbackStatus::Retrying])
            ->count();

        return number_format($failed / $total * 100, 1);
    }
}
