<?php

namespace App\Jobs;

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Services\Money\MacroResolver;
use App\Services\Money\UrlRedactor;
use App\Support\AdminAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Our call to the publisher — docs/04-conversions-postbacks.md §2.
 *
 * Every attempt writes its own row. One row per conversion with a counter loses
 * what each attempt actually returned, which is exactly what an integration
 * dispute turns on.
 */
class DispatchPublisherPostback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const USER_AGENT = 'AdVault-Postback/1.0';

    private const ERROR_LIMIT = 1000;

    public int $tries = 6;

    public int $timeout = 20;

    /**
     * 1m, 5m, 30m, 2h, 6h.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 1800, 7200, 21600];

    public function __construct(
        public Conversion $conversion,
        public OutgoingPostbackKind $kind = OutgoingPostbackKind::Credit,
    ) {}

    public function handle(): void
    {
        $app = $this->conversion->app;

        // Publisher polls the dashboard instead — legitimate configuration.
        if ($app === null || blank($app->postback_url)) {
            return;
        }

        $attempt = max($this->attempts(), 1);
        $url = MacroResolver::resolve($app->postback_url, $this->conversion, $this->kind);

        $log = OutgoingPostback::create([
            'conversion_id' => $this->conversion->id,
            'app_id' => $app->id,
            'kind' => $this->kind,
            'url' => UrlRedactor::redact($url),
            'method' => $app->postback_method,
            'attempt' => $attempt,
            'status' => OutgoingPostbackStatus::Queued,
            'created_at' => now(),
        ]);

        $started = hrtime(true);

        try {
            $response = Http::timeout(15)
                ->withUserAgent(self::USER_AGENT)
                ->send($app->postback_method->value, $url);
        } catch (ConnectionException $e) {
            // A publisher whose DNS is down looks nothing like a 500 and has to
            // be retried the same way.
            $this->retryLater($log, $attempt, [
                'error' => Str::limit($e->getMessage(), self::ERROR_LIMIT),
                'duration_ms' => $this->elapsed($started),
            ]);

            return;
        }

        $attributes = [
            'http_status' => $response->status(),
            'response_body' => Str::limit($response->body(), OutgoingPostback::RESPONSE_BODY_LIMIT),
            'duration_ms' => $this->elapsed($started),
        ];

        // Success is 2xx only. A publisher returning 500 for an hour is a
        // publisher we keep retrying, not one we mark delivered.
        if ($response->successful()) {
            $log->update([...$attributes, 'status' => OutgoingPostbackStatus::Success]);

            return;
        }

        $this->retryLater($log, $attempt, $attributes);
    }

    public function failed(Throwable $e): void
    {
        OutgoingPostback::query()
            ->where('conversion_id', $this->conversion->id)
            ->latest('id')
            ->first()
            ?->update([
                'status' => OutgoingPostbackStatus::Failed,
                'error' => Str::limit($e->getMessage(), self::ERROR_LIMIT),
            ]);

        // docs/04 §2 calls this `PostbackFailureAlert::notify()`. AdminAlert is
        // that seam and already exists, so this is the same alert under the
        // name the rest of the system raises alerts by. It still writes the
        // critical log line; it now also emails whoever is on the list.
        AdminAlert::raise('postback.failed', $this->conversion, [
            'conversion_id' => $this->conversion->id,
            'trans_id' => $this->conversion->trans_id,
            'kind' => $this->kind->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function retryLater(OutgoingPostback $log, int $attempt, array $attributes): void
    {
        $delay = $this->backoff[$attempt - 1] ?? 21600;

        $log->update([
            ...$attributes,
            'status' => OutgoingPostbackStatus::Retrying,
            'next_retry_at' => now()->addSeconds($delay),
        ]);

        $this->release($delay);
    }

    private function elapsed(float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1e6);
    }
}
