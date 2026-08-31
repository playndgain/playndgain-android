<?php

use App\Models\Network;
use Illuminate\Database\Migrations\Migration;

/**
 * Monlix's credential keys were renamed underneath the rows that hold them:
 * `app_id` became `api_key`, `secret` became `secret_key`.
 *
 * Nothing else moved the stored values, so on an install with a live Monlix
 * network both `credential()` lookups returned null the moment the new driver
 * shipped. That is not a cosmetic break. `authParams()` sends an empty appid and
 * a token md5'd over two empty strings, so the catalogue comes back empty and
 * the sync job's collapse guard abandons the run; and `verifyPostback()` sees a
 * blank secret and fails closed, rejecting *every* inbound Monlix conversion
 * until an admin notices and retypes credentials they have no reason to think
 * are gone. Conversions rejected at the ingress are money we never bill for.
 *
 * Only Monlix. Two other drivers had keys renamed in the same commit —
 * `admantium` (`api_key` → `appid`) and `adswedmedia` (`api_key` → `site_key`) —
 * but both were StubDriver subclasses that threw on every call, so the old key
 * held StubDriver's placeholder rather than a credential that ever worked, and
 * both now require a second key a migration cannot invent. Moving a placeholder
 * into a required slot would only make an unconfigured row look configured.
 *
 * The `where` clause is load-bearing, not tidiness. Notik's *new* schema
 * legitimately owns an `app_id`, so an unscoped "move app_id to api_key" would
 * destroy a Notik credential an admin had just entered correctly.
 *
 * Goes through the model because `networks.credentials` is an `encrypted:array`
 * cast — a raw SQL UPDATE would write plaintext into a column everything else
 * reads through the decrypter. The coupling to App\Models\Network that migrations
 * normally avoid is the price of the encryption, and it is the only way to do
 * this at all.
 */
return new class extends Migration
{
    /** Old key => new key. */
    private const RENAMED = [
        'app_id' => 'api_key',
        'secret' => 'secret_key',
    ];

    public function up(): void
    {
        $this->move(self::RENAMED);
    }

    /**
     * Honest, and genuinely reversible: rolling back restores the driver whose
     * schema wants the old names, so the credentials go back under them.
     */
    public function down(): void
    {
        $this->move(array_flip(self::RENAMED));
    }

    /**
     * @param  array<string, string>  $map
     */
    private function move(array $map): void
    {
        // `networks.driver` is unique, so there is at most one row to fix.
        $network = Network::query()->where('driver', 'monlix')->first();

        if ($network === null || ! is_array($network->credentials)) {
            return;
        }

        $credentials = $network->credentials;

        foreach ($map as $from => $to) {
            // Filled destination wins. An admin who has already retyped the
            // credentials by hand must not have the good value overwritten by
            // the stale one — and dropping the old key either way is what makes
            // a second run of this migration a no-op.
            if (array_key_exists($from, $credentials) && blank($credentials[$to] ?? null)) {
                $credentials[$to] = $credentials[$from];
            }

            unset($credentials[$from]);
        }

        $network->update(['credentials' => $credentials]);
    }
};
