<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\BillPayment;
use App\Models\Estate\Document;
use App\Models\Estate\Meeting;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Services\Documents\Documents;
use App\Services\Estate\Governance;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asking for a document, and fetching one — 12 §1.
 *
 * EVERY KIND IS GATED ON ITS OWN MODULE, not on a documents permission. A
 * statement is a household's financial position and needs Dues & ledger; a
 * receipt needs Payments; minutes and a certificate need Governance. A single
 * "documents" gate would be a way of reading any of them through one hole.
 *
 * NOTHING HERE RENDERS IN THE REQUEST. Every kind queues, hands back the row,
 * and the screen says what it is waiting for — see `Documents`.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    /** A unit's statement of account — board 6's "Print statement". */
    public function statement(Request $request, Unit $unit): RedirectResponse
    {
        $document = $this->documents->request(
            kind: Document::STATEMENT,
            title: 'Statement of account — '.$unit->reference,
            filename: 'statement-'.strtolower(str_replace(' ', '-', $unit->reference)).'.pdf',
            by: $request->user(),
            subjectType: 'unit',
            subjectId: (string) $unit->id,
        );

        return back()->with('success', $this->sentence($document));
    }

    /** One payment's receipt — board 27's "View receipt", and the register's. */
    public function receipt(Request $request, Payment $payment): RedirectResponse
    {
        $document = $this->documents->request(
            kind: Document::RECEIPT,
            title: 'Receipt '.$payment->receipt_no,
            filename: 'receipt-'.strtolower($payment->receipt_no).'.pdf',
            by: $request->user(),
            subjectType: 'payment',
            subjectId: (string) $payment->id,
        );

        return back()->with('success', $this->sentence($document));
    }

    /**
     * The remittance advice for a bill the estate paid — board 27's row action.
     *
     * The control reads "View receipt" because the board draws it that way, and
     * what it issues is a REMITTANCE ADVICE: a receipt is issued by whoever
     * received the money, and the estate is the payer. See `Document::REMITTANCE`.
     */
    public function remittance(Request $request, BillPayment $payment): RedirectResponse
    {
        $document = $this->documents->request(
            kind: Document::REMITTANCE,
            title: 'Remittance advice — '.($payment->bill->vendor->name ?? 'supplier'),
            filename: 'remittance-'.$payment->id.'.pdf',
            by: $request->user(),
            subjectType: 'bill_payment',
            subjectId: (string) $payment->id,
        );

        return back()->with('success', $this->sentence($document));
    }

    /** A meeting's agenda or its minutes — board 36's row action. */
    public function meetingPaper(Request $request, Meeting $meeting, string $kind): RedirectResponse
    {
        if (! in_array($kind, [Document::AGENDA, Document::MINUTES], true)) {
            return back()->withErrors(['document' => 'A meeting issues an agenda or its minutes.']);
        }

        if ($kind === Document::MINUTES && $meeting->minutes === null) {
            return back()->withErrors(['document' => 'No minutes have been recorded for '.$meeting->title.' yet. A blank page that looks like a record of a meeting is worse than no page.']);
        }

        $document = $this->documents->request(
            kind: $kind,
            title: ucfirst($kind).' — '.$meeting->title,
            filename: $kind.'-'.$meeting->id.'.pdf',
            by: $request->user(),
            subjectType: 'meeting',
            subjectId: (string) $meeting->id,
        );

        return back()->with('success', $this->sentence($document));
    }

    /** The election certificate — board 11's "Export report". */
    public function certificate(Request $request, int $year, Governance $governance): RedirectResponse
    {
        $results = $governance->resultsBoard($year);

        if (($results['ballot']['id'] ?? null) === null) {
            return back()->withErrors(['document' => 'There is no ballot for '.$year.'.']);
        }

        try {
            // Asked here as well as in the renderer, so the refusal reaches the
            // reader now rather than as a failed document four seconds later.
            $governance->certificateData((int) $results['ballot']['id']);
        } catch (DomainException $refused) {
            return back()->withErrors(['document' => $refused->getMessage()]);
        }

        $document = $this->documents->request(
            kind: Document::ELECTION_CERTIFICATE,
            title: 'Election certificate — '.$year,
            filename: 'election-certificate-'.$year.'.pdf',
            by: $request->user(),
            subjectType: 'ballot',
            subjectId: (string) $results['ballot']['id'],
        );

        return back()->with('success', $this->sentence($document));
    }

    /**
     * Fetch a document that is ready.
     *
     * STREAMED FROM THE PRIVATE DISK, never linked to. The file lives under
     * this estate's own prefix and this route is inside the estate's auth
     * group, so one estate's statements cannot be fetched from another's URL.
     */
    public function download(Document $document): StreamedResponse
    {
        abort_unless($document->isReady(), 404);

        $path = (string) $document->path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->stream(function () use ($path): void {
            echo Storage::disk('local')->get($path);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * What to tell somebody who just pressed.
     *
     * A QUEUED DOCUMENT IS NOT A FAILURE. Saying it is being made, and that
     * pressing again hands back this same one, is what stops four identical
     * statements existing for one balance.
     */
    private function sentence(Document $document): string
    {
        return $document->isReady()
            ? $document->title.' is ready.'
            : $document->title.' is being produced. It is rendered by a worker rather than while you wait — pressing again will hand you this same document rather than making a second one.';
    }
}
