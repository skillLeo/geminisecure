<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An invoice Gemini Security raises against a client estate.
 *
 * Not to be confused with a resident charge, which lives in the estate
 * database. These are two separate ledgers and the distinction is load-bearing:
 * an overdue platform invoice must never restrict a resident.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $subscription_id
 * @property string $reference
 * @property string $period
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $total_minor
 * @property string $currency
 * @property Carbon $due_on
 * @property string $status
 * @property Carbon|null $paid_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $total
 * @property-read Tenant|null $estate
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read int|null $lines_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDueOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePaidOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTotalMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Invoice extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'reference', 'period',
        'period_start', 'period_end', 'total_minor', 'currency',
        'due_on', 'status', 'paid_on',
    ];

    protected function casts(): array
    {
        return [
            'total' => MoneyCast::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'paid_on' => 'date',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Overdue is derived, not stored.
     *
     * An invoice that passes its due date overnight is overdue the moment it
     * is asked about, rather than whenever a scheduled job next runs.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'issued' && $this->due_on->isPast();
    }

    public function statusBadge(): string
    {
        return match (true) {
            $this->status === 'paid' => 'paid',
            $this->isOverdue() => 'overdue',
            $this->status === 'issued' => 'due',
            default => 'ok',
        };
    }
}
