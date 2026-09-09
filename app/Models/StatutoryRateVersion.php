<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A dated set of statutory rates.
 *
 * Versioned data with effective dates, never constants. A payroll run records
 * the version it used so it reproduces exactly, to the cent, years later.
 *
 * Rates are basis points: 3% is 300. No float ever touches a wage.
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
