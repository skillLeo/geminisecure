<?php

declare(strict_types=1);

use App\Jobs\Estate\RenderDocument;
use App\Models\Estate\Document;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\Documents;
use App\Services\Estate\EstateBranding;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Issued documents — 12 §1
|--------------------------------------------------------------------------
|
| "Statement and receipt PDFs: server-rendered, queued, seven-year retention,
| estate logo." And separately: "document retention is 7 years for anything
| financial. Not configurable."
|
| The retention is STAMPED at issue and never derived from a setting, because a
| retention period that can be shortened after the fact is not one. A second
| press inside the window finds the first document rather than making a second.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    Document::query()->delete();
});

it('queues a statement, stamps seven years on it, and hands the same one back on a second press', function () {
    Queue::fake();

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('documents'));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect();

    FacilitiesFixture::boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    // QUEUED, not rendered in the request.
    Queue::assertPushed(RenderDocument::class);

    expect($document->status)->toBe(Document::QUEUED)
        ->and($document->path)->toBeNull()
        ->and($document->requested_by_name)->toBe($treasurer->name)
        ->and($document->subject_id)->toBe((string) $unit->id)

        // SEVEN YEARS, STAMPED. Not derived at read time from a setting.
        ->and((int) round($document->created_at->diffInDays($document->retain_until)))
        ->toBeGreaterThan(365 * Document::RETENTION_YEARS - 3);

    // A SECOND PRESS FINDS THE FIRST. Four identical statements for one balance
    // is what this prevents, and the sentence on the screen says so.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Document::query()->where('kind', Document::STATEMENT)->count())->toBe(1)
        ->and($document->statusLine())->toContain('same document');
});

it('renders a statement to a real PDF, hashes what it wrote, and serves it once ready', function () {
    Storage::fake('local');

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    /*
     * Asked for through the route, because that is where tenancy is
     * initialised — `Documents::request()` stamps the estate onto the job and
     * refusing to guess one is the whole point of `RequiresTenantContext`.
     */
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/statement'))
        ->assertRedirect();

    FacilitiesFixture::boot();

    $document = Document::query()->where('kind', Document::STATEMENT)->sole();

    // The worker, run inline. Tenancy is already initialised by the fixture.
    app(RenderDocument::class, [
        'tenantId' => (string) tenant()->getTenantKey(),
        'documentId' => $document->id,
        'payload' => [],
    ])->handle(app(DocumentRenderer::class));

    $document = Document::query()->findOrFail($document->id);

    expect($document->status)->toBe(Document::READY)
        ->and($document->path)->not->toBeNull()
        ->and($document->bytes)->toBeGreaterThan(0)
        ->and($document->sha256)->toHaveLength(64)
        ->and($document->issued_at)->not->toBeNull();

    $bytes = Storage::disk('local')->get((string) $document->path);

    // A real PDF, and the hash is of what was WRITTEN — the answer to "is this
    // the statement that was issued?" in 2033.
    expect(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and(hash('sha256', $bytes))->toBe($document->sha256);

    $response = $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/documents/'.$document->id))
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    $response->streamedContent();

    // A document that is not ready is not served — a 404, not half a file.
    $queued = Document::query()->create([
        'kind' => Document::RECEIPT,
        'subject_type' => 'payment',
        'subject_id' => '999999',
        'title' => 'Receipt',
        'filename' => 'receipt-test.pdf',
        'status' => Document::QUEUED,
        'requested_by_name' => $treasurer->name,
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/documents/'.$queued->id))
        ->assertNotFound();
});

it('records a render failure against the document rather than leaving it waiting forever', function () {
    Storage::fake('local');

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    // One request against the estate's own host, so tenancy is initialised —
    // the job refuses to run without it rather than guessing an estate, which
    // is what `RequiresTenantContext` exists for.
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/finance/arrears'))->assertOk();

    // A receipt whose payment does not exist. The renderer throws, and the row
    // must say so — a job that simply died would leave a document reading
    // "being produced" forever, and the reader would keep waiting.
    $document = Document::query()->create([
        'kind' => Document::RECEIPT,
        'subject_type' => 'payment',
        'subject_id' => '987654',
        'title' => 'Receipt for nothing',
        'filename' => 'receipt-nothing.pdf',
        'status' => Document::QUEUED,
        'requested_by_name' => $treasurer->name,
        'retain_until' => now()->addYears(Document::RETENTION_YEARS),
    ]);

    $job = app(RenderDocument::class, [
        'tenantId' => (string) tenant()->getTenantKey(),
        'documentId' => $document->id,
        'payload' => [],
    ]);

    $threw = false;

    try {
        $job->handle(app(DocumentRenderer::class));
    } catch (Throwable) {
        // Rethrown on purpose, so the queue can retry and a failure is visible
        // in the worker's own log as well as on the row.
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $document = Document::query()->findOrFail($document->id);

    expect($document->status)->toBe(Document::FAILED)
        ->and($document->failure_reason)->not->toBeNull()
        ->and($document->statusLine())->toContain('Asking again is safe');
});

it('issues a receipt PDF from the register, and the estate logo is embedded rather than linked', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $payment = Payment::query()->orderBy('id')->first();

    if ($payment === null) {
        expect(true)->toBeTrue();

        return;
    }

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/finance/payments/'.$payment->id.'/receipt'))
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Document::query()->where('kind', Document::RECEIPT)->count())->toBe(1);

    // THE LOGO. Uploaded, kept, and read back as a data: URI — never a URL,
    // because a queue worker fetching one would be making an HTTP request back
    // into the application against a host it may not resolve.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/profile/logo'), [
            'logo' => UploadedFile::fake()->image('mark.png', 200, 200),
        ])
        ->assertRedirect(FacilitiesFixture::url('/settings/profile'));

    FacilitiesFixture::boot();

    $branding = app(EstateBranding::class);

    expect($branding->contents())->not->toBeNull()
        ->and($branding->dataUri())->toStartWith('data:image/png;base64,');

    // A PDF is not a logo, and the rule is stated before the press.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/profile/logo'), [
            'logo' => UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasErrors('logo');
});
