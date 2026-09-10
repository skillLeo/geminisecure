<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person in a household. Estate database only.
 *
 * `user_id` points at the central users table and is deliberately not a
 * foreign key: it crosses a database boundary, which MySQL cannot constrain.
 * Integrity is the service layer's job.
 *
 * `status` IS ABOUT IDENTITY AND NOTHING ELSE. Board 34's preview copy states
 * the transition outright — "Once she verifies with her ID, her status changes
 * from Pending to Verified automatically" — so this column answers "has this
 * person proved who they are", and it answers nothing about money. Board 4 puts
 * a "Pending review" badge against a household with a zero balance for exactly
 * that reason: verification and arrears are independent, and a screen that let
 * one imply the other would tell a reviewer something untrue about both.
 *
 * `biometric_consent` SHIPS OFF AND IS NOT A SETTING (D-022, Q-003). It is per
 * person, because consent is; there is no estate-level switch that grants it on
 * anybody's behalf, and `Residents::enrolBiometrics()` refuses without it rather
 * than trusting the screen that collected it.
 *
 * `moved_in_on` IS NOT `created_at`. Board 38 draws "Resident since Sep 2,
 * 2024"; an estate that imported its register last month did not house everybody
 * last month, and the date a row was keyed is a fact about the software.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $user_id
 * @property string $full_name
 * @property string|null $email
 * @property string|null $phone
 * @property string $relationship
 * @property bool $is_primary
 * @property string $status
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property string|null $verified_by_name
 * @property bool $biometric_consent
 * @property Carbon|null $moved_in_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Household|null $household
 * @property-read Collection<int, ResidentInvite> $invites
 * @property-read int|null $invites_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident query()
 *
 * @mixin \Eloquent
 */
class Resident extends Model
{
    /** On the register, identity not yet established. */
    public const PENDING = 'pending';

    /** Identity established, by an ID check or by the estate's own roll. */
    public const VERIFIED = 'verified';

    /** What each state is called on screen. */
    public const STATUS_LABELS = [
        self::PENDING => 'Pending review',
        self::VERIFIED => 'Verified',
    ];

    protected $fillable = [
        'household_id',
        'user_id',
        'full_name',
        'email',
        'phone',
        'relationship',
        'is_primary',
        'status',
        'verified_at',
        'verified_by',
        'verified_by_name',
        'biometric_consent',
        'moved_in_on',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'biometric_consent' => 'boolean',
            'verified_at' => 'datetime',
            'moved_in_on' => 'date',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<ResidentInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(ResidentInvite::class);
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /**
     * The two-letter avatar every resident row draws.
     *
     * First and last initial — "Andrea Fletcher" reads "AF", which is what
     * board 4 draws on all six of its rows. A single-word name gives its first
     * two letters, because a one-letter avatar reads as a rendering fault.
     */
    public function initials(): string
    {
        $parts = array_values(array_filter(explode(' ', trim($this->full_name))));

        if ($parts === []) {
            return '??';
        }

        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper($parts[0][0].$parts[count($parts) - 1][0]);
    }

    /**
     * The name as a household panel prints it — "Marlon Fletcher (brother)".
     *
     * The primary resident is marked as primary rather than by their
     * relationship, because board 38 draws "Andrea Fletcher (primary)" over a
     * row whose relationship is `owner`: which of them holds the household is
     * the fact the panel is reporting.
     */
    public function panelName(): string
    {
        if ($this->is_primary) {
            return $this->full_name.' (primary)';
        }

        $relationship = trim($this->relationship);

        return $relationship === '' || $relationship === 'owner'
            ? $this->full_name
            : $this->full_name.' ('.$relationship.')';
    }
}
