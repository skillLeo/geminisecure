<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Payslip extends Model
{
    use CentralConnection;

    protected $fillable = [
        'payroll_run_id', 'guard_id', 'tenant_id',
        'gross_minor', 'nis_minor', 'nht_minor',
        'education_tax_minor', 'paye_minor', 'net_minor',
        'currency', 'paye_note',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** Not named guard(): Model::guard(array) already exists in Eloquent. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
