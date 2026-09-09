<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A guarded position at an estate: a gate, a patrol route, a relief slot.
 *
 * Central alongside guards, because rostering is a Gemini operation spanning
 * every client rather than something each estate manages for itself.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string $type
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Collection<int, Guard> $guards
 * @property-read int|null $guards_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Post extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<Guard, $this> */
    public function guards(): HasMany
    {
        return $this->hasMany(Guard::class);
    }
}
