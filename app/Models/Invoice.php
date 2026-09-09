<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An invoice Gemini Security raises against a client estate.
 *
 * Not to be confused with a resident charge, which lives in the estate
 * database. These are two separate ledgers and the distinction is load-bearing:
 * an overdue platform invoice must never restrict a resident.
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

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

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
        return $this->status === 'issued' && $this->due_on?->isPast();
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
