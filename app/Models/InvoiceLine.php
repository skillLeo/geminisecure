<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class InvoiceLine extends Model
{
    use CentralConnection;

    protected $fillable = [
        'invoice_id', 'description', 'quantity',
        'unit_price_minor', 'total_minor', 'currency',
    ];

    protected function casts(): array
    {
        return ['total' => MoneyCast::class];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
