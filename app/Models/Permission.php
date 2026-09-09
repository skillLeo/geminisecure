<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Permissions live in gs_platform, never per estate.
 *
 * See App\Models\Role for why this pinning is required rather than optional.
 */
class Permission extends SpatiePermission
{
    use CentralConnection;
}
