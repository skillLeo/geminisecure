<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per estate, holding that estate's configurable thresholds.
 *
 * Estate database only, so each community's settings are physically separate
 * and one estate cannot read or change another's.
 *
 * @property int $id
 * @property int $arrears_restriction_days
 * @property int $arrears_notice_days
 * @property bool $arrears_restriction_enabled
 */
class EstateSetting extends Model
{
    /** The client's defaults (D-024), applied to a new estate. */
    public const DEFAULT_RESTRICTION_DAYS = 90;

    public const DEFAULT_NOTICE_DAYS = 14;

    protected $fillable = [
        'arrears_restriction_days',
        'arrears_notice_days',
        'arrears_restriction_enabled',
    ];

    protected function casts(): array
    {
        return [
            'arrears_restriction_days' => 'integer',
            'arrears_notice_days' => 'integer',
            'arrears_restriction_enabled' => 'boolean',
        ];
    }

    /**
     * This estate's settings, creating the defaults on first read.
     *
     * Returns a real row rather than a null-object, so a caller reading
     * `arrears_notice_days` always gets a number and never has to decide what
     * a missing setting means.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'arrears_restriction_days' => self::DEFAULT_RESTRICTION_DAYS,
            'arrears_notice_days' => self::DEFAULT_NOTICE_DAYS,
            'arrears_restriction_enabled' => true,
        ]);
    }
}
