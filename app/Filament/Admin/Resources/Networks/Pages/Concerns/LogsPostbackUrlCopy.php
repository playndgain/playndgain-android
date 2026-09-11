<?php

namespace App\Filament\Admin\Resources\Networks\Pages\Concerns;

use App\Models\AuditLog;
use App\Models\Network;

/**
 * The audit half of "Copy postback URL".
 *
 * The copy itself stays an Alpine click handler. `clipboard.writeText()` needs
 * the browser's transient activation from the click that triggered it, and a
 * server round-trip spends it — Safari refuses the write outright once the
 * gesture is gone. So the clipboard is written synchronously in the handler and
 * the handler then calls this method, which is the only part that needs a
 * server.
 *
 * The URL carries the row's postback secret in the clear, which is the whole
 * reason the field on screen shows it masked. Reading the real one is the same
 * class of act as revealing credentials, and that already writes to the audit
 * log (docs/07 §4).
 */
trait LogsPostbackUrlCopy
{
    public function logPostbackUrlCopy(int|string $networkId): void
    {
        $network = Network::query()->find($networkId);

        if ($network === null) {
            return;
        }

        AuditLog::record('network.postback_url_copied', $network);
    }
}
