<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A dated set of statutory rates.
 *
 * Versioned data with effective dates, never constants. A payroll run records
 * the version it used so it reproduces exactly, to the cent, years later.
 *
 * Rates are basis points: 3% is 300. No float ever touches a wage.
 *
 * @property int $id
 * @property string $label
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $nis_employee_bp
 * @property int $nis_employer_bp
 * @property int $nis_ceiling_annual_minor
 * @property int $nht_employee_bp
 * @property int $nht_employer_bp
 * @property int $education_tax_employee_bp
 * @property int $education_tax_employer_bp
 * @property int $paye_bp
 * @property int $paye_threshold_annual_minor
 * @property bool $is_verified
 * @property string|null $verified_by
 * @property Carbon|null $verified_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEducationTaxEmployeeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEducationTaxEmployerBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEffectiveFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEffectiveTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNhtEmployeeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNhtEmployerBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNisCeilingAnnualMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNisEmployeeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNisEmployerBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion wherePayeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion wherePayeThresholdAnnualMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereVerifiedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereVerifiedOn($value)
 *
 * @mixin \Eloquent
 */
class StatutoryRateVersion extends Model
{
    use CentralConnection;

    protected $fillable = [
        'label', 'effective_from', 'effective_to',
        'nis_employee_bp', 'nis_employer_bp', 'nis_ceiling_annual_minor',
        'nht_employee_bp', 'nht_employer_bp',
        'education_tax_employee_bp', 'education_tax_employer_bp',
        'paye_bp', 'paye_threshold_annual_minor',
        'is_verified', 'verified_by', 'verified_on',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'verified_on' => 'date',
            'is_verified' => 'boolean',
        ];
    }

    /** The version in force on a given date. */
    public static function inForceOn(string $date): ?self
    {
        return static::where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();
    }
}
