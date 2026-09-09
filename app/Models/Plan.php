<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Plan extends Model
{
    use CentralConnection;

    protected $fillable = [
        'key', 'name', 'description', 'price_per_unit_minor',
        'currency', 'min_units', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price_per_unit' => MoneyCast::class.':price_per_unit_minor,currency',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
