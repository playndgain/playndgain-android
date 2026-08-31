<?php

namespace App\Networks\Drivers;

use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Request;

/**
 * The test double. Every conversion-pipeline test uses this; nothing else
 * touches HTTP.
 *
 * Scripted through static setters so a test can arrange the catalogue or the
 * postback it wants without a container binding. `reset()` runs between tests.
 */
class FakeNetworkDriver extends AbstractDriver
{
    /** @var list<OfferDTO> */
    protected static array $offers = [];

    protected static ?PostbackDTO $postback = null;

    protected static bool $signatureValid = true;

    protected static bool $connectionOk = true;

    /** Set when fetchOffers() should blow up mid-catalogue. */
    protected static ?\Throwable $fetchException = null;

    public static function key(): string
    {
        return 'fake';
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => ['label' => 'API Key', 'required' => true, 'secret' => true],
        ];
    }

    // ---------------------------------------------------------------------
    // Scripting
    // ---------------------------------------------------------------------

    /**
     * @param  list<OfferDTO>  $offers
     */
    public static function shouldReturnOffers(array $offers): void
    {
        static::$offers = $offers;
    }

    /**
     * Build `$count` throwaway offers. For the collapse guard, which cares
     * about catalogue size and nothing else.
     *
     * @return list<OfferDTO>
     */
    public static function makeOffers(int $count, float $payout = 1.0): array
    {
        return collect(range(1, max($count, 0)))
            ->map(fn (int $i) => new OfferDTO(
                externalId: "fake-{$i}",
                name: "Fake Offer {$i}",
                trackingUrl: "https://fake.test/click?offer={$i}&subid={click_id}",
                payout: $payout,
                countries: ['US'],
                devices: ['android'],
            ))
            ->all();
    }

    public static function shouldParsePostback(?PostbackDTO $dto): void
    {
        static::$postback = $dto;
    }

    public static function shouldVerifySignature(bool $valid): void
    {
        static::$signatureValid = $valid;
    }

    public static function shouldConnect(bool $ok): void
    {
        static::$connectionOk = $ok;
    }

    public static function shouldThrowOnFetch(?\Throwable $e): void
    {
        static::$fetchException = $e;
    }

    public static function reset(): void
    {
        static::$offers = [];
        static::$postback = null;
        static::$signatureValid = true;
        static::$connectionOk = true;
        static::$fetchException = null;
    }

    // ---------------------------------------------------------------------
    // Driver
    // ---------------------------------------------------------------------

    public function testConnection(): ConnectionResult
    {
        return static::$connectionOk
            ? ConnectionResult::ok('Connected.', count(static::$offers))
            : ConnectionResult::fail('Invalid credentials.');
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        if (static::$fetchException !== null) {
            throw static::$fetchException;
        }

        yield from static::$offers;
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        return static::$postback;
    }

    public function verifyPostback(Request $request): bool
    {
        return static::$signatureValid;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }
}
