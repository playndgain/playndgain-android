<?php

namespace App\Networks;

use App\Enums\ConnectorCategory;

/**
 * The facts about a driver class that the rest of the app is allowed to know.
 *
 * Everything here is a property of the *integration*, not of any one `networks`
 * row, which is why it is static on the driver rather than a column. Three of
 * the four fields exist because something outside `app/Networks/` has to make a
 * decision and would otherwise hard-code a driver key to make it:
 *
 *   - `label` — the admin select showed raw keys (`adgatemedia`) as both value
 *     and label. A human name belongs with the class that owns the key.
 *   - `supportsMultipleAccounts` — a platform hosts one tenant per partner, so
 *     several rows legitimately share the driver. Everything else is one
 *     account and a second row is a mistake the create form refuses.
 *   - `rejectedAfterCreditReverses` — whether an upstream `rejected` arriving
 *     after we already credited means the money comes back. It does on the
 *     tracking platforms; on MyChips and Offery a late `rejected` never meant a
 *     chargeback, and reading it as one would claw back paid conversions.
 *     Phase 2 reads this; it is declared here so the connector owns the fact.
 *   - `listedInAdmin` — whether New network may pick this driver. The test
 *     double stays registered so demo rows and pipeline tests still resolve,
 *     but it is not an account an admin should create.
 *   - `website` / `logo` — the identity the create form fills when this
 *     connector is picked. `logo` is a filename under `public/img/networks`;
 *     when it is omitted the file matching the driver key is used if it exists.
 */
final readonly class Connector
{
    public function __construct(
        public string $label,
        public ConnectorCategory $category,
        public bool $supportsMultipleAccounts = false,
        public bool $rejectedAfterCreditReverses = false,
        public bool $listedInAdmin = true,
        public ?string $website = null,
        public ?string $logo = null,
    ) {}

    /**
     * Path under `public/` for this connector's mark, if one is on disk.
     *
     * An explicit `logo` wins. Otherwise `{driver}.{svg,png,webp}` is tried
     * so a partner that already ships a file does not need a second mention.
     */
    public function logoPathFor(string $driver): ?string
    {
        if (filled($this->logo)) {
            $path = str_contains($this->logo, '/') ? $this->logo : 'img/networks/'.$this->logo;

            return is_file(public_path($path)) ? $path : null;
        }

        foreach (['svg', 'png', 'webp'] as $extension) {
            $path = 'img/networks/'.$driver.'.'.$extension;

            if (is_file(public_path($path))) {
                return $path;
            }
        }

        return null;
    }

    public function logoUrlFor(string $driver): ?string
    {
        $path = $this->logoPathFor($driver);

        return $path === null ? null : asset($path);
    }

    /**
     * Identity the create form writes when this card is picked.
     *
     * @return array{name: string, website: ?string, logo_path: ?string}
     */
    public function formDefaults(string $driver): array
    {
        return [
            'name' => $this->label,
            'website' => $this->website,
            'logo_path' => $this->logoPathFor($driver),
        ];
    }
}
