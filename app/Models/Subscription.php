<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An estate's subscription to GeminiSecure.
 *
 * `dunning` and `suspended` gate BILLING features only. Neither ever restricts
 * entry, a safety function, or a resident. Access is never withheld over a
 * billing dispute.
 */
class Subscription extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id', 'plan_id', 'unit_count',
        'status', 'started_on', 'renews_on',
    ];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'renews_on' => 'date'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Monthly recurring revenue in minor units. */
    public function mrrMinor(): int
    {
        return $this->unit_count * ($this->plan?->price_per_unit_minor ?? 0);
    }
}
