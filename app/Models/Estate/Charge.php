<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge raised against a household.
 *
 * Lives only in gs_estate_<subdomain>. There is no tenant_id column: the
 * database boundary is the tenant boundary, and a second source of truth
 * could only ever disagree with the first.
 *
 * No model in this namespace declares a connection. Under
 * DatabaseTenancyBootstrapper the default connection IS the current estate,
 * so a query with no tenant context fails to resolve rather than silently
 * reading central data.
 */
class Charge extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'reference',
        'description',
        'amount_minor',
        'currency',
        'due_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'due_on' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
