<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Estate\BillPayment;
use App\Models\Estate\Document;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Meeting;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use App\Services\Estate\EstateBranding;
use App\Services\Estate\Governance;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * What each kind of document actually says.
 *
 * ONE PLACE THE FACTS ARE GATHERED, and they are gathered at RENDER time from
 * the same services the screens read. A statement is a snapshot: the balance it
 * prints is the balance the ledger held the moment the worker ran, and the PDF
 * keeps it. Re-deriving it years later from live data would show a resident a
 * figure that was never on the paper they hold — which is the whole reason the
 * bytes are stored and hashed rather than the document being a view.
 *
 * THE LOGO IS EMBEDDED AS BYTES, never fetched by URL — see `EstateBranding`.
 */
class DocumentRenderer
{
    public function __construct(
        private readonly Dues $dues,
        private readonly Governance $governance,
        private readonly EstateBranding $branding,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Document $document, array $payload = []): string
    {
        $setting = EstateSetting::query()->first();

        $shared = [
            'estateName' => (string) tenant()->name,
            'logo' => $this->branding->dataUri(),
            'enquiries' => $setting?->enquiries_email,
            'phone' => $setting?->enquiries_phone,
            'issuedAt' => Carbon::now()->format('F j, Y \a\t g:i A'),
            'issuedBy' => $document->requested_by_name,
            'retainUntil' => $document->retain_until->format('F j, Y'),
            'title' => $document->title,
        ];

        [$view, $data] = match ($document->kind) {
            Document::STATEMENT => $this->statement($document),
            Document::RECEIPT => $this->receipt($document),
            Document::MINUTES, Document::AGENDA => $this->meetingPaper($document),
            Document::ELECTION_CERTIFICATE => $this->certificate($document, $payload),
            Document::REMITTANCE => $this->remittance($document),
            default => throw new DomainException('No renderer for a '.$document->kind.'.'),
        };

        return Pdf::loadView($view, [...$shared, ...$data])
            ->setPaper('a4')
            ->output();
    }

    /**
     * A unit's statement of account.
     *
     * EVERY LINE COMES FROM THE LEDGER, through the same `unitBoard()` the
     * screen reads. A statement built from a second query would be a document
     * that could disagree with the screen it was printed from, and the resident
     * holding it would be right and the office wrong.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function statement(Document $document): array
    {
        $unit = Unit::query()->findOrFail((int) $document->subject_id);
        $board = $this->dues->unitBoard($unit);

        /*
         * THE WHOLE HISTORY, OLDEST FIRST. The screen shows the five most recent
         * movements newest-first, because that is what somebody glancing at an
         * account wants. A statement is read the other way — it is the account
         * from the beginning, and the running balance only makes sense read
         * downwards.
         */
        $lines = array_reverse($this->dues->statement($unit, PHP_INT_MAX));

        return ['documents.statement', [
            'unit' => $board['unit'],
            'balanceMinor' => $board['balance_minor'],
            'bucketLabel' => $board['bucket_label'],
            'strip' => $board['strip'],
            'statement' => $lines,
        ]];
    }

    /**
     * One payment's receipt — the document the resident keeps.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function receipt(Document $document): array
    {
        $payment = Payment::query()->with('unit')->findOrFail((int) $document->subject_id);

        return ['documents.receipt', [
            'payment' => [
                'receipt_no' => $payment->receipt_no,
                'unit' => $payment->unit->reference,
                'amount_minor' => $payment->amount_minor,
                'method' => ucfirst($payment->method),
                'reference' => $payment->reference,
                'received_on' => $payment->received_at->format('F j, Y'),
                'received_by' => $payment->received_by_name,
            ],
        ]];
    }

    /**
     * What the estate sends a supplier it has paid.
     *
     * A REMITTANCE ADVICE, NOT A RECEIPT. A receipt is issued by whoever
     * received the money, so the estate cannot issue one for a bill it paid —
     * see `Document::REMITTANCE`. This says what was paid, against which
     * invoice, on what date and by what method, which is what a supplier's
     * accounts department actually needs to clear their own ledger.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function remittance(Document $document): array
    {
        $payment = BillPayment::query()
            ->with(['bill.vendor'])
            ->findOrFail((int) $document->subject_id);

        return ['documents.remittance', [
            'payment' => [
                'vendor' => $payment->bill->vendor->name ?? 'Supplier',
                'vendor_trn' => $payment->bill->vendor->trn ?? null,
                'invoice' => $payment->bill->referenceLabel(),
                'description' => $payment->bill->description,
                'invoice_minor' => $payment->bill->amount_minor,
                'paid_minor' => $payment->amount_minor,
                'method' => ucfirst($payment->method),
                'reference' => $payment->reference,
                'paid_on' => $payment->paid_on->format('F j, Y'),
                'paid_by' => $payment->paid_by_name,
            ],
        ]];
    }

    /**
     * A meeting's agenda or its minutes.
     *
     * MINUTES ARE ONLY ISSUED ONCE THEY EXIST. A blank minutes document would
     * be a piece of paper that looks like a record of a meeting and is not.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function meetingPaper(Document $document): array
    {
        $meeting = Meeting::query()->with(['agenda', 'minutes'])->findOrFail((int) $document->subject_id);

        if ($document->kind === Document::MINUTES && $meeting->minutes === null) {
            throw new DomainException('That meeting has no minutes recorded, so there is nothing to issue.');
        }

        return ['documents.meeting', [
            'kind' => $document->kind,
            'meeting' => [
                'title' => $meeting->title,
                'type' => $meeting->typeLabel(),
                'starts_at' => $meeting->starts_at->format('l, F j, Y \a\t g:i A'),

                /*
                 * The venue, and the link where there is no room. A hybrid
                 * meeting has both and a virtual one has only the link; a
                 * paper agenda saying "To be confirmed" for a meeting whose
                 * link is on record would send somebody looking for a hall.
                 */
                'location' => implode(' · ', array_filter([$meeting->venue, $meeting->virtual_link])),

                'agenda' => $meeting->agenda
                    ->sortBy('sort_order')
                    ->map(static fn ($item): array => [
                        'time' => $item->start_time,
                        'text' => $item->text,
                        'motion' => $item->motion_reference,
                    ])
                    ->values()
                    ->all(),

                'minutes' => $meeting->minutes?->body,
                'minutes_adopted' => $meeting->minutes?->adopted_at?->format('F j, Y'),
                'minutes_by' => $meeting->minutes?->recorded_by_name,
                'quorum' => $meeting->quorum_percent,
            ],
        ]];
    }

    /**
     * The election certificate — who was elected, on what count.
     *
     * THE FIGURES ARE THE TALLY'S, read back rather than recomputed. A
     * certificate is the document an estate files; a second arithmetic over the
     * same ballots would be a second answer to a question already decided.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function certificate(Document $document, array $payload): array
    {
        return ['documents.certificate', [
            'election' => $this->governance->certificateData((int) $document->subject_id),
            'note' => $payload['note'] ?? null,
        ]];
    }
}
