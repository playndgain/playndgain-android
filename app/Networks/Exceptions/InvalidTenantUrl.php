<?php

namespace App\Networks\Exceptions;

use RuntimeException;

/**
 * An admin-supplied API host that `TenantUrl` refused.
 *
 * Its own class so the sync job's catch can tell a misconfigured tenant from a
 * partner having a bad morning, and so the message — which never contains a
 * credential — is safe to put on `networks.last_sync_error`.
 */
class InvalidTenantUrl extends RuntimeException {}
