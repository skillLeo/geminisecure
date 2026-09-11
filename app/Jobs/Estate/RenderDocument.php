<?php

declare(strict_types=1);

namespace App\Jobs\Estate;

use App\Jobs\Concerns\RequiresTenantContext;
use App\Models\Estate\Document;
use App\Services\Documents\DocumentRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a queued document into a file on disk — 12 §1's "server-rendered,
 * queued".
 *
 * IT WRITES A ROW EITHER WAY. A render that throws marks the document FAILED
 * with the reason, and the screen says so; a job that simply died would leave a
 * document reading "being produced" forever, and the reader would keep waiting
 * for a file nobody is making.
 *
 * THE HASH IS TAKEN OF WHAT WAS WRITTEN, not of what was meant to be. It is the
 * answer to "is this the statement that was issued?" in 2033, and a hash of the
 * intended content would answer a different question.
 */
class RenderDocument implements ShouldQueue
{
    use Queueable;
    use RequiresTenantContext;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public int $documentId,
        public array $payload = [],
    ) {}

    public function handle(DocumentRenderer $renderer): void
    {
        $this->assertTenantContext();

        $document = Document::query()->find($this->documentId);

        if ($document === null || $document->status !== Document::QUEUED) {
            return;
        }

        try {
            $bytes = $renderer->render($document, $this->payload);

            $path = 'estates/'.$this->tenantId.'/documents/'.$document->id.'-'.$document->filename;

            Storage::disk('local')->put($path, $bytes);

            $document->forceFill([
                'status' => Document::READY,
                'path' => $path,
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'issued_at' => Carbon::now(),
                'failure_reason' => null,
            ])->save();
        } catch (Throwable $failure) {
            $document->forceFill([
                'status' => Document::FAILED,

                /*
                 * The message, not the stack. This sentence is shown to an
                 * officer on a settings screen, and a file path from a vendor
                 * directory tells them nothing and tells an attacker something.
                 */
                'failure_reason' => mb_substr($failure->getMessage(), 0, 240),
            ])->save();

            throw $failure;
        }
    }
}
