<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Refills `database/fixtures/offer-art` from the stores the demo apps ship on.
 *
 * The fixtures are committed, so this is not part of seeding and nothing on a
 * developer's box needs the network. It exists so the art is reproducible
 * rather than a folder of images nobody can re-derive: the manifest names each
 * app by storefront, numeric store id, bundle identifier and seller of record,
 * and this command refuses to write a file unless the bundle and the seller
 * both still match. A lookup by name would happily return a similarly named
 * game; an id on its own can be reassigned to a different app entirely.
 *
 *     php artisan demo:offer-art            # fetch and write
 *     php artisan demo:offer-art --check    # verify the manifest, write nothing
 *
 * Only the square icon is taken. The lookup publishes screenshots alongside it
 * and no promotional banner, and a screenshot is not a banner — see the
 * manifest's own note.
 */
class FetchDemoOfferArtCommand extends Command
{
    protected $signature = 'demo:offer-art
        {--check : Verify every entry still resolves, and write nothing}';

    protected $description = 'Refetch the demo catalogue\'s offer artwork from the stores its apps ship on';

    /** Icons are 512px squares. Anything much larger is not one. */
    private const MAX_BYTES = 512 * 1024;

    private const SIZE = 512;

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:offer-art refuses to run in production.');

            return self::FAILURE;
        }

        $directory = base_path('database/fixtures/offer-art');
        $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true);

        if (! is_array($manifest['art'] ?? null)) {
            $this->error('No `art` block in database/fixtures/offer-art/manifest.json.');

            return self::FAILURE;
        }

        $check = (bool) $this->option('check');
        $failed = 0;

        foreach ($manifest['art'] as $externalId => $entry) {
            $problem = $this->one($directory, (string) $externalId, $entry, $check);

            if ($problem !== null) {
                $this->components->twoColumnDetail((string) $externalId, "<fg=red>{$problem}</>");
                $failed++;

                continue;
            }

            $this->components->twoColumnDetail((string) $externalId, '<fg=green>'.($check ? 'verified' : 'written').'</>');
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error("{$failed} of ".count($manifest['art']).' entries did not resolve. Nothing was written for those.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Every entry resolved to the app the manifest names.');

        return self::SUCCESS;
    }

    /**
     * One entry, end to end. Returns the reason it was skipped, or null.
     *
     * `$entry` is whatever `json_decode` found under that key, so it is mixed
     * and stays mixed: a hand-edited manifest is the likeliest thing in this
     * flow to be wrong, and the guards below are the ones that say so instead
     * of throwing on a missing index.
     */
    private function one(string $directory, string $externalId, mixed $entry, bool $check): ?string
    {
        if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
            return 'malformed manifest entry';
        }

        // The filename reaches a filesystem path. The manifest is ours, but a
        // path traversal in a fixture name is a bad thing to make possible at
        // all, so the name has to be a plain one.
        if (preg_match('/^[a-z0-9-]+\.jpg$/', $entry['file']) !== 1) {
            return 'file name is not a plain lowercase .jpg';
        }

        try {
            $response = Http::timeout(15)->get('https://itunes.apple.com/lookup', [
                'id' => $entry['store_id'] ?? '',
                'country' => $entry['storefront'] ?? 'us',
            ]);
        } catch (Throwable $e) {
            return 'lookup failed: '.$e->getMessage();
        }

        if (! $response->successful()) {
            return 'lookup returned HTTP '.$response->status();
        }

        $result = $response->json('results.0');

        if (! is_array($result)) {
            return 'store id resolves to nothing on that storefront';
        }

        // The whole point of the manifest. An id that has been reassigned, or a
        // seller that has changed hands, is not the app we said it was, and the
        // honest outcome is no art rather than the wrong app's art.
        if (($result['bundleId'] ?? null) !== ($entry['bundle_id'] ?? null)) {
            return 'bundle id is now '.(string) ($result['bundleId'] ?? 'absent');
        }

        if (($result['sellerName'] ?? null) !== ($entry['seller'] ?? null)) {
            return 'seller is now '.(string) ($result['sellerName'] ?? 'absent');
        }

        $artwork = $result['artworkUrl512'] ?? null;

        if (! is_string($artwork) || $artwork === '') {
            return 'the listing publishes no 512px artwork';
        }

        if ($check) {
            return null;
        }

        try {
            $image = Http::timeout(20)->get($artwork);
        } catch (Throwable $e) {
            return 'artwork fetch failed: '.$e->getMessage();
        }

        $body = $image->successful() ? $image->body() : '';

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return 'artwork is empty or over '.(self::MAX_BYTES / 1024).' KB';
        }

        // Decoded rather than trusted: the fixture is committed and then served
        // through the icon proxy, and a file that is not the square JPEG the
        // card reserves space for is a layout shift on every wall that renders.
        $size = @getimagesizefromstring($body);

        if ($size === false || $size[2] !== IMAGETYPE_JPEG) {
            return 'artwork did not decode as a JPEG';
        }

        if ($size[0] !== self::SIZE || $size[1] !== self::SIZE) {
            return "artwork is {$size[0]}×{$size[1]}, not ".self::SIZE.'² ';
        }

        file_put_contents($directory.'/'.$entry['file'], $this->stripMetadata($body));

        return null;
    }

    /**
     * Drops every metadata segment, keeping the compressed image untouched.
     *
     * The artwork arrives with an EXIF UserComment holding the store's own
     * asset token and a Photoshop resource block holding a digest. Neither is
     * a demand network's identifier, but both are an asset host's, and both
     * would ride into the offerwall through the icon proxy and out of it again
     * on a publisher's page. AGENTS.md draws that line at image metadata
     * explicitly, so nothing that identifies where a file came from is kept.
     *
     * A segment walk rather than a re-encode: re-encoding through GD would
     * strip the same markers and quietly recompress ten pieces of artwork that
     * are already at the quality the store published. Everything from the
     * first scan onward is copied verbatim, so the pixels are bit-identical.
     */
    private function stripMetadata(string $jpeg): string
    {
        $out = "\xFF\xD8";           // SOI
        $at = 2;
        $length = strlen($jpeg);

        while ($at + 3 < $length) {
            if ($jpeg[$at] !== "\xFF") {
                break;
            }

            $marker = ord($jpeg[$at + 1]);

            // Start of scan: the entropy-coded data runs to the end of the
            // file and carries no markers worth parsing. Copy the rest as is.
            if ($marker === 0xDA) {
                return $out.substr($jpeg, $at);
            }

            $size = 2 + ((ord($jpeg[$at + 2]) << 8) | ord($jpeg[$at + 3]));

            // APPn (0xE0–0xEF) is every metadata container — EXIF, JFIF, XMP,
            // the Photoshop block — and 0xFE is a comment. Everything else is
            // structural: quantisation tables, Huffman tables, the frame
            // header. Those are what makes the image decodable.
            $isMetadata = ($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE;

            if (! $isMetadata) {
                $out .= substr($jpeg, $at, $size);
            }

            $at += $size;
        }

        // A file this walk could not follow is returned whole rather than
        // truncated: a broken icon is worse than a retained comment, and the
        // decode check above has already established that it is a JPEG.
        return $at + 3 < $length ? $jpeg : $out;
    }
}
