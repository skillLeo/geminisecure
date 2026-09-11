<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\EstateAssignment;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The two acts board 35 draws on an invoice — 12 §2, Wave 4.
 *
 * A RAISED INVOICE IS NEVER EDITED. `BillingController` says so and there is no
 * route that would: a correction is a credit note, which is a new posted record
 * of its own, and the invoice keeps its total, its lines and its reference.
 *
 * BOTH ACTS REACH OUTSIDE THIS CONSOLE, which is what the old reason said and
 * why they are audited: one sends a client an email, the other changes what they
 * owe. Neither is data entry.
 */
class InvoiceActions
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Send the invoice to whoever holds the estate's account.
     *
     * WHO IT GOES TO IS DERIVED, not typed. The estate's active officers are
     * who the platform knows; a free-text address on this screen would be one
     * nobody could check, and an invoice going to a typo is a conversation
     * about money that never happened.
     *
     * @return list<string> the addresses it went to
     */
    public function resend(int $invoiceId, User $by): array
    {
        $invoice = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.id', $invoiceId)
            ->select('invoices.*', 'tenants.name as estate')
            ->first();

        if ($invoice === null) {
            throw new DomainException('That invoice is not on this platform.');
        }

        if ($invoice->status === 'draft') {
            throw new DomainException('That invoice has not been issued. Sending a draft would put a figure in a client\'s hands that the platform has not committed to.');
        }

        $officers = EstateAssignment::query()
            ->with('user')
            ->where('tenant_id', $invoice->tenant_id)
            ->where('is_active', true)
            ->get()
            ->map(static fn (EstateAssignment $assignment): User => $assignment->user)
            ->filter(static fn (User $user): bool => $user->status === 'active' && trim((string) $user->email) !== '')
            ->unique('id')
            ->values();

        if ($officers->isEmpty()) {
            throw new DomainException(
                'Nobody active holds an account at '.$invoice->estate.', so there is no address to send to. '.
                'An invoice emailed nowhere is worse than one not emailed.'
            );
        }

        Notification::send($officers, new InvoiceIssued(
            reference: (string) $invoice->reference,
            period: (string) $invoice->period,
            totalMinor: (int) $invoice->total_minor,
            currency: (string) $invoice->currency,
            dueOn: Carbon::parse((string) $invoice->due_on)->format('F j, Y'),
            estate: (string) $invoice->estate,
            isResend: true,
        ));

        /** @var list<string> $addresses */
        $addresses = $officers->pluck('email')->all();

        DB::connection('mysql')->table('invoice_sends')->insert([
            'invoice_id' => $invoiceId,
            'recipients' => mb_substr(implode(', ', $addresses), 0, 500),
            'sent_by_id' => $by->getKey(),
            'sent_by_name' => (string) $by->name,
            'sent_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'billing.invoice_resent',
            entityType: 'Invoice',
            entityId: (string) $invoiceId,
            after: ['reference' => (string) $invoice->reference, 'recipients' => $addresses],
            tenantId: (string) $invoice->tenant_id,
        );

        return $addresses;
    }

    /**
     * Issue a credit note against an invoice.
     *
     * NEVER MORE THAN THE INVOICE, LESS WHAT IS ALREADY CREDITED. A credit note
     * for more than was charged is money the platform is giving back that it
     * never took, and two notes that together exceed the invoice are the same
     * mistake made twice.
     *
     * THE INVOICE IS NOT TOUCHED. Its total is what was charged; what it is
     * worth now is that total less its credit notes, and every screen that
     * shows one shows both.
     *
     * @return array{reference: string, amount_minor: int}
     */
    public function creditNote(int $invoiceId, string $amount, string $reason, User $by): array
    {
        $invoice = DB::connection('mysql')->table('invoices')->where('id', $invoiceId)->first();

        if ($invoice === null) {
            throw new DomainException('That invoice is not on this platform.');
        }

        if ($invoice->status === 'draft') {
            throw new DomainException('A draft invoice is corrected by raising it correctly. A credit note corrects one a client has already been given.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why. A credit note with no stated reason is one nobody can review a year later, which is exactly what it exists to be.');
        }

        $minor = (int) round((float) $amount * 100);

        if ($minor <= 0) {
            throw new DomainException('A credit note is a positive amount. What it does to the account is what the words mean; a sign would let one be entered as a charge.');
        }

        $alreadyCredited = (int) DB::connection('mysql')
            ->table('credit_notes')
            ->where('invoice_id', $invoiceId)
            ->sum('amount_minor');

        if ($minor + $alreadyCredited > (int) $invoice->total_minor) {
            throw new DomainException(sprintf(
                'That would credit more than the invoice charged. %s is charged, %s is already credited.',
                number_format((int) $invoice->total_minor / 100, 2),
                number_format($alreadyCredited / 100, 2),
            ));
        }

        $reference = $this->nextReference((string) $invoice->reference);

        DB::connection('mysql')->table('credit_notes')->insert([
            'tenant_id' => (string) $invoice->tenant_id,
            'invoice_id' => $invoiceId,
            'reference' => $reference,
            'amount_minor' => $minor,
            'currency' => (string) $invoice->currency,
            'reason' => $reason,
            'issued_by_id' => $by->getKey(),
            'issued_by_name' => (string) $by->name,
            'issued_on' => Carbon::today()->toDateString(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'billing.credit_note_issued',
            entityType: 'Invoice',
            entityId: (string) $invoiceId,
            after: [
                'credit_note' => $reference,
                'amount_minor' => $minor,
                'reason' => $reason,
                'invoice' => (string) $invoice->reference,
            ],
            tenantId: (string) $invoice->tenant_id,
        );

        return ['reference' => $reference, 'amount_minor' => $minor];
    }

    /**
     * The credit notes against one invoice, and what it is worth now.
     *
     * @return array{notes: list<array<string, mixed>>, credited_minor: int}
     */
    public function creditsFor(int $invoiceId): array
    {
        $notes = DB::connection('mysql')
            ->table('credit_notes')
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->get();

        return [
            'notes' => $notes->map(static fn (object $note): array => [
                'reference' => (string) $note->reference,
                'amount_minor' => (int) $note->amount_minor,
                'reason' => (string) $note->reason,
                'issued_on' => Carbon::parse((string) $note->issued_on)->format('M j, Y'),
                'issued_by' => (string) $note->issued_by_name,
            ])->all(),
            'credited_minor' => (int) $notes->sum('amount_minor'),
        ];
    }

    /**
     * The sends already made, so "did they get it?" has an answer.
     *
     * @return list<array<string, mixed>>
     */
    public function sendsFor(int $invoiceId): array
    {
        return DB::connection('mysql')
            ->table('invoice_sends')
            ->where('invoice_id', $invoiceId)
            ->orderByDesc('sent_at')
            ->limit(10)
            ->get()
            ->map(static fn (object $send): array => [
                'recipients' => (string) $send->recipients,
                'by' => (string) $send->sent_by_name,
                'at' => Carbon::parse((string) $send->sent_at)->format('M j, Y g:i A'),
            ])
            ->all();
    }

    /**
     * "INV-2026-08-004-CN1" — the invoice's own reference with a suffix.
     *
     * Derived from the invoice rather than from a sequence of its own: a credit
     * note is only ever read beside the invoice it corrects, and a reference
     * that says which one saves a lookup on every conversation about it.
     */
    private function nextReference(string $invoiceReference): string
    {
        $n = 1;

        while (DB::connection('mysql')->table('credit_notes')->where('reference', $invoiceReference.'-CN'.$n)->exists()) {
            $n++;
        }

        return $invoiceReference.'-CN'.$n;
    }
}
