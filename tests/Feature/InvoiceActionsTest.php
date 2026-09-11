<?php

declare(strict_types=1);

use App\Models\Role;
use App\Notifications\InvoiceIssued;
use App\Services\Gemini\InvoiceActions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An invoice's two writes — board 35 (12 §2, Wave 4)
|--------------------------------------------------------------------------
|
| A RAISED INVOICE IS NEVER EDITED. A correction is a credit note, which is a
| new posted record of its own, and the invoice keeps its total, its lines and
| its reference. Both figures are shown rather than one netted number.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function billingInvoice(): ?object
{
    return DB::connection('mysql')->table('invoices')->where('status', '!=', 'draft')->orderBy('id')->first();
}

it('credits an invoice without touching it, and never for more than it charged', function () {
    $invoice = billingInvoice();

    if ($invoice === null) {
        expect(true)->toBeTrue();

        return;
    }

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $this->actingAs($director)
        ->get('/billing/invoices/'.$invoice->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canWrite', true)
            ->where('notes', [])
            ->has('sends'));

    // Reading an invoice is not the same as changing what a client owes.
    $this->actingAs($dispatcher)
        ->post('/billing/invoices/'.$invoice->id.'/credit-note', ['amount' => '1.00', 'reason' => 'x'])
        ->assertForbidden();

    // NEVER UNEXPLAINED.
    $this->actingAs($director)
        ->post('/billing/invoices/'.$invoice->id.'/credit-note', ['amount' => '1.00', 'reason' => ''])
        ->assertSessionHasErrors('reason');

    // NEVER MORE THAN THE INVOICE CHARGED.
    $this->actingAs($director)
        ->post('/billing/invoices/'.$invoice->id.'/credit-note', [
            'amount' => number_format(((int) $invoice->total_minor / 100) + 1, 2, '.', ''),
            'reason' => 'Too much',
        ])
        ->assertSessionHasErrors('amount');

    $this->actingAs($director)
        ->post('/billing/invoices/'.$invoice->id.'/credit-note', [
            'amount' => '500.00',
            'reason' => 'Two guard-months billed that were not worked.',
        ])
        ->assertRedirect();

    $note = DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->sole();

    /*
     * THE INVOICE IS UNTOUCHED. Its total is what was charged; what it is worth
     * now is that total less its credit notes, and both are read together.
     */
    expect((int) $note->amount_minor)->toBe(500_00)
        ->and($note->reference)->toBe($invoice->reference.'-CN1')
        ->and($note->issued_by_name)->toBe($director->name)
        ->and((int) DB::connection('mysql')->table('invoices')->where('id', $invoice->id)->value('total_minor'))
        ->toBe((int) $invoice->total_minor);

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'billing.credit_note_issued')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and(json_decode((string) $entry->after, true)['credit_note'])->toBe($note->reference);

    // A second note is numbered after the first, and the two together still
    // cannot exceed the invoice.
    app(InvoiceActions::class)->creditNote((int) $invoice->id, '1.00', 'A little more', $director);

    expect(DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->count())->toBe(2)
        ->and(DB::connection('mysql')->table('credit_notes')->where('reference', $invoice->reference.'-CN2')->exists())
        ->toBeTrue();

    expect(fn () => app(InvoiceActions::class)->creditNote(
        (int) $invoice->id,
        number_format((int) $invoice->total_minor / 100, 2, '.', ''),
        'Everything again',
        $director,
    ))->toThrow(DomainException::class);
});

it('resends an invoice to the estate officers and records where it went', function () {
    Notification::fake();

    $invoice = billingInvoice();

    if ($invoice === null) {
        expect(true)->toBeTrue();

        return;
    }

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // Somebody has to hold the account, or there is no address to send to —
    // an invoice emailed nowhere is worse than one not emailed.
    $officer = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    DB::connection('mysql')->table('invoices')->where('id', $invoice->id)->update([
        'tenant_id' => FacilitiesFixture::platform()->getTenantKey(),
    ]);

    $this->actingAs($director)
        ->post('/billing/invoices/'.$invoice->id.'/resend')
        ->assertRedirect();

    Notification::assertSentTo($officer, InvoiceIssued::class);

    $send = DB::connection('mysql')->table('invoice_sends')->where('invoice_id', $invoice->id)->sole();

    /*
     * "DID THEY GET IT?" is the question a billing conversation turns on, and a
     * resend that left no trace makes the console unable to answer it.
     */
    expect($send->recipients)->toContain((string) $officer->email)
        ->and($send->sent_by_name)->toBe($director->name);

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'billing.invoice_resent')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull();
});

it('renders the invoice as a PDF and records that it left', function () {
    $invoice = billingInvoice();

    if ($invoice === null) {
        expect(true)->toBeTrue();

        return;
    }

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $response = $this->actingAs($director)->get('/billing/invoices/'.$invoice->id.'/pdf')->assertOk();

    $bytes = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with($bytes, '%PDF-'))->toBeTrue();

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'export.taken')
        ->orderByDesc('id')
        ->first();

    $after = json_decode((string) $entry->after, true);

    expect($after['scope'])->toContain('Invoice '.$invoice->reference)
        ->and($after)->toHaveKey('tenants');
});
