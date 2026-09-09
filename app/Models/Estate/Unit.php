<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical address within one estate. Estate database only. */
class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['reference', 'block', 'street', 'type', 'status'];

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
