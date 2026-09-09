<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A guarded position at an estate: a gate, a patrol route, a relief slot.
 *
 * Central alongside guards, because rostering is a Gemini operation spanning
 * every client rather than something each estate manages for itself.
 */
class Post extends Model
{
    use CentralConnection;
    use HasFactory;

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

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function guards(): HasMany
    {
        return $this->hasMany(Guard::class);
    }
}
