<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Domain;
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
 *
 * @property string $id
 * @property string $name
 * @property string $status
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<array-key, mixed>|null $data
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Domain> $domains
 * @property-read int|null $domains_count
 * @property-read string $subdomain
 *
 * @method static \Stancl\Tenancy\Database\TenantCollection<int, static> all($columns = ['*'])
 * @method static \Stancl\Tenancy\Database\TenantCollection<int, static> get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereProvisionedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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
     *
     * @return list<string>
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

    /**
     * The fields every estate listing shows.
     *
     * On the model rather than in each controller so the Gemini Console, the
     * dashboard and the future /api/v1 estate endpoint cannot describe the
     * same estate differently.
     *
     * @return array{id: string, name: string, status: string, provisioned_at: string|null}
     */
    public function toSummary(): array
    {
        return [
            'id' => (string) $this->getTenantKey(),
            'name' => $this->name,
            'status' => $this->status,
            'provisioned_at' => $this->provisioned_at?->toDateString(),
        ];
    }

    /**
     * Every estate, ordered by subdomain, correctly typed.
     *
     * stancl overrides newCollection() to return its own TenantCollection,
     * which erases the element type: `Tenant::all()` is inferred as a
     * collection of plain Models, so `$estate->name` and `$estate->run()` both
     * disappear from static analysis at every call site.
     *
     * Narrowing it once here is better than repeating a cast in every seeder,
     * command and controller that iterates estates.
     *
     * @param  (callable(Builder<self>): void)|null  $shape
     * @return Collection<int, self>
     */
    public static function estates(?callable $shape = null): Collection
    {
        // self, not static: static::query() is typed Builder<static>, which is
        // not covariant with the Builder<self> the callable declares.
        $query = self::query();

        if ($shape !== null) {
            $shape($query);
        } else {
            $query->orderBy('id');
        }

        /** @var Collection<int, self> $estates */
        $estates = $query->get()->values();

        return $estates;
    }
}
