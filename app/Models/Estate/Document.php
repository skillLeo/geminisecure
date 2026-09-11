<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A document the estate issued, and has to keep for seven years (12 §1).
 *
 * @property int $id
 * @property string $kind
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $title
 * @property string $filename
 * @property string $status
 * @property string|null $path
 * @property int|null $bytes
 * @property string|null $sha256
 * @property string|null $failure_reason
 * @property int|null $requested_by_id
 * @property string $requested_by_name
 * @property Carbon|null $issued_at
 * @property Carbon $retain_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Document extends Model
{
    /**
     * SEVEN YEARS, AND NOT CONFIGURABLE (12 §1).
     *
     * A constant rather than a setting, because a retention period that can be
     * shortened after the fact is not a retention period. It is stamped onto
     * the row at issue so that changing this constant tomorrow cannot shorten
     * the life of a document issued today.
     */
    public const RETENTION_YEARS = 7;

    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const STATEMENT = 'statement';

    public const RECEIPT = 'receipt';

    public const MINUTES = 'minutes';

    public const AGENDA = 'agenda';

    public const ELECTION_CERTIFICATE = 'election_certificate';

    /** @var array<string, string> */
    public const KIND_LABELS = [
        self::STATEMENT => 'Statement of account',
        self::RECEIPT => 'Payment receipt',
        self::MINUTES => 'Meeting minutes',
        self::AGENDA => 'Meeting agenda',
        self::ELECTION_CERTIFICATE => 'Election certificate',
    ];

    protected $connection = 'tenant';

    protected $fillable = [
        'kind',
        'subject_type',
        'subject_id',
        'title',
        'filename',
        'status',
        'path',
        'bytes',
        'sha256',
        'failure_reason',
        'requested_by_id',
        'requested_by_name',
        'issued_at',
        'retain_until',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'issued_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->path !== null;
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /**
     * What the screen says about a document while it waits, or when it is here.
     *
     * A QUEUED DOCUMENT IS NOT A FAILURE AND MUST NOT READ AS ONE. Somebody who
     * pressed "Print statement" ten seconds ago and sees nothing will press it
     * again; telling them it is being made, and that pressing again gets the
     * same one, is what stops four identical statements existing.
     */
    public function statusLine(): string
    {
        return match ($this->status) {
            self::READY => 'Issued '.($this->issued_at?->format('M j, Y g:i A') ?? '').'. Kept until '.$this->retain_until->format('M j, Y').'.',
            self::FAILED => 'Could not be produced: '.($this->failure_reason ?? 'the renderer did not answer').'. Asking again is safe — nothing was issued.',
            default => 'Being produced. It is rendered by a worker rather than while you wait, so asking again will hand you this same document rather than making a second one.',
        };
    }
}
