<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Jobs\Estate\RenderDocument;
use App\Models\Estate\Document;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Asking for a document, and finding the one already asked for (12 §1).
 *
 * SERVER-RENDERED AND QUEUED, which is the ruling and also the only honest
 * shape: a statement is a page of rendered HTML turned into a PDF, and doing
 * that inside the request means an estate with four hundred units waits on the
 * slowest one. So the press records the request, hands back immediately, and a
 * worker writes the file.
 *
 * A SECOND PRESS FINDS THE FIRST DOCUMENT. Somebody who sees nothing after ten
 * seconds presses again; without this, four identical statements exist and the
 * resident has four different file names for one balance. `existing()` is what
 * makes the second press idempotent — within the window, and only while the
 * subject has not changed under it.
 */
class Documents
{
    /**
     * How long a document counts as "the one you just asked for".
     *
     * FIFTEEN MINUTES, and it is deliberately short. A statement is a snapshot
     * of a balance: handing somebody yesterday's when they asked today would be
     * showing them a figure that is no longer true. Long enough to cover a
     * queue that is behind and a reader pressing twice; short enough that
     * "today's statement" means today's.
     */
    public const REUSE_MINUTES = 15;

    /**
     * Ask for a document. Returns the one already being made, if there is one.
     *
     * @param  array<string, mixed>  $payload  what the renderer needs that is not on the subject
     */
    public function request(
        string $kind,
        string $title,
        string $filename,
        User $by,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $payload = [],
    ): Document {
        if (! array_key_exists($kind, Document::KIND_LABELS)) {
            throw new DomainException('That is not a kind of document this estate issues.');
        }

        $existing = $this->existing($kind, $subjectType, $subjectId);

        if ($existing !== null) {
            return $existing;
        }

        $document = Document::create([
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'title' => $title,
            'filename' => $filename,
            'status' => Document::QUEUED,
            'requested_by_id' => $by->getKey(),
            'requested_by_name' => (string) $by->name,

            /*
             * STAMPED AT REQUEST, seven years out, and never derived at read
             * time from a setting — a retention period that can be shortened
             * after the fact is not a retention period (12 §1).
             */
            'retain_until' => Carbon::now()->addYears(Document::RETENTION_YEARS),
        ]);

        RenderDocument::dispatch(
            (string) tenant()->getTenantKey(),
            $document->id,
            $payload,
        );

        return $document;
    }

    /**
     * The document for this subject that is still current, or null.
     *
     * A FAILED ONE IS NOT CURRENT. Somebody pressing again after a failure is
     * asking for another attempt, and handing them the failure back would be a
     * screen that cannot be got past.
     */
    public function existing(string $kind, ?string $subjectType, ?string $subjectId): ?Document
    {
        return Document::query()
            ->where('kind', $kind)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('status', [Document::QUEUED, Document::READY])
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::REUSE_MINUTES))
            ->latest('id')
            ->first();
    }

    /**
     * Every document issued about one subject, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forSubject(string $subjectType, string $subjectId, int $limit = 12): array
    {
        return Document::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(static fn (Document $document): array => [
                'id' => $document->id,
                'kind' => $document->kind,
                'kind_label' => $document->kindLabel(),
                'title' => $document->title,
                'status' => $document->status,
                'status_line' => $document->statusLine(),
                'is_ready' => $document->isReady(),
                'issued_at' => $document->issued_at?->format('M j, Y g:i A'),
                'retain_until' => $document->retain_until->format('M j, Y'),
            ])
            ->all();
    }
}
