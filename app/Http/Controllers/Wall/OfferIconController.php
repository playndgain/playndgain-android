<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/img/offer/{offer}` — docs/03 §6.
 *
 * Icons are proxied and cached locally, never hotlinked. Networks rotate and
 * delete their CDN assets without warning, and a wall of broken images reads as
 * a dead product. The copy also means the wall makes no third-party request
 * from inside a publisher's page.
 *
 * ORCHESTRATOR: `routes/web.php` is outside this phase's scope, so the route
 * still has to be added — one line, inside the existing `wall` group:
 *
 *     Route::get('/img/offer/{offer}', OfferIconController::class)->name('wall.icon');
 */
class OfferIconController extends Controller
{
    /** Icons are 64–256 px squares. Anything larger is not an icon. */
    private const MAX_BYTES = 512 * 1024;

    /** @var array<string, string> extension => content type */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public function __invoke(Offer $offer): Response
    {
        $path = $this->stored($offer) ?? $this->fetch($offer);

        // The card falls back to its gradient tile, which is also what happens
        // when the network deleted the asset — one visual outcome, not two.
        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()->file(Storage::disk('local')->path($path), [
            // Hashed by offer ID and immutable: an offer that changes its art
            // is a new sync, and the sync clears the copy.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function stored(Offer $offer): ?string
    {
        foreach (array_keys(self::TYPES) as $extension) {
            $path = $this->path($offer, $extension);

            if (Storage::disk('local')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function fetch(Offer $offer): ?string
    {
        // A dead icon URL must not cost an outbound request per card per user.
        if (blank($offer->icon_url) || Cache::get($this->missKey($offer)) === true) {
            return null;
        }

        try {
            $response = Http::timeout(5)->withUserAgent('AdVault/1.0')->get((string) $offer->icon_url);
        } catch (Throwable) {
            $response = null;
        }

        $type = $response?->successful() === true ? strtok((string) $response->header('Content-Type'), ';') : false;
        $extension = array_search(trim((string) $type), self::TYPES, true);
        $body = $response?->body() ?? '';

        if ($extension === false || $body === '' || strlen($body) > self::MAX_BYTES) {
            Cache::put($this->missKey($offer), true, 3600);

            return null;
        }

        $path = $this->path($offer, $extension);
        Storage::disk('local')->put($path, $body);

        return $path;
    }

    private function path(Offer $offer, string $extension): string
    {
        return "offer-icons/{$offer->id}.{$extension}";
    }

    private function missKey(Offer $offer): string
    {
        return "offer:icon:miss:{$offer->id}";
    }
}
