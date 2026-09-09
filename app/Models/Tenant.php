<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * An estate.
 *
 * Each one owns a physically separate database, `gs_estate_<subdomain>`, with
 * its own MySQL user. A leak between two communities is therefore a connection
 * error rather than a query error, which is much harder to cause by accident
 * than a forgotten WHERE clause.
 *
 * The tenant id is the subdomain, so stancl's `prefix + id` yields the database
 * name directly with nothing to keep in sync.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected $guarded = [];

    protected $keyType = 'string';

    /**
     * The key is the subdomain, supplied explicitly at provisioning.
     *
     * This must be a method override, not the `$incrementing` property.
     * stancl's GeneratesIds trait defines:
     *
     *     public function getIncrementing()
     *     {
     *         return ! app()->bound(UniqueIdentifierGenerator::class);
     *     }
     *
     * Since `tenancy.id_generator` is deliberately null (the id is the
     * subdomain, not a UUID), that generator is unbound and the trait reports
     * `true` — silently overriding the property. Eloquent then casts
     * 'phoenixpark' to 0, and every estate collides on `gs_estate_0`.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Columns promoted out of stancl's virtual `data` JSON column.
     *
     * Anything listed here is a real column and is queryable; anything else
     * set on the model lands in `data`.
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'provisioned_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
        ];
    }

    /** The subdomain is the id — they are the same fact, stored once. */
    public function getSubdomainAttribute(): string
    {
        return (string) $this->getTenantKey();
    }
}
