<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One payroll run. APPEND-ONLY once approved (invariant 4).
 *
 * A correction to an approved run is a new adjustment run referencing it,
 * never an edit.
 */
class PayrollRun extends Model
{
    use CentralConnection;

    protected $fillable = [
        'reference', 'period_label', 'period_start', 'period_end',
        'periods_per_year', 'statutory_rate_version_id', 'status',
        'gross_minor', 'net_minor', 'currency', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function rateVersion(): BelongsTo
    {
        return $this->belongsTo(StatutoryRateVersion::class, 'statutory_rate_version_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * A run may be calculated against unverified rates, so the figures can be
     * checked, but never APPROVED against them.
     *
     * Build Spec open item [A]: do not go live on unverified numbers.
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'calculated' && (bool) $this->rateVersion?->is_verified;
    }

    public function blockedReason(): ?string
    {
        if ($this->status !== 'calculated') {
            return null;
        }

        return $this->rateVersion?->is_verified
            ? null
            : 'Statutory rates for this period are unverified. An accountant must sign them off before this run can be approved.';
    }

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            $wasApproved = in_array($run->getOriginal('status'), ['approved', 'paid'], true);
            $markingPaid = $run->getOriginal('status') === 'approved' && $run->status === 'paid';

            if ($wasApproved && ! $markingPaid) {
                throw new LogicException(
                    "Payroll run [{$run->reference}] is approved and cannot be edited. "
                    .'Post an adjustment run referencing it instead.'
                );
            }
        });
    }
}
