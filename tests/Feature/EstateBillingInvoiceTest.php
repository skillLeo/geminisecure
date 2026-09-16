<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An estate's own invoice, itemised — board 40's "View" (12 §2, Wave 4)
|--------------------------------------------------------------------------
|
| A CENTRAL RECORD READ FROM THE ESTATE'S HOSTNAME. The route proves this
| estate owns the invoice; another client's invoice id is a 404, the same as an
| id that does not exist, so guessing ids tells an estate nothing.
|
| THE INVOICE AS ISSUED. Every line, the subscription lines marked, and credit
| notes shown beside the total rather than netted into it.
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

/** An issued invoice with a subscription line and a guard add-on line. */
function estateInvoice(string $tenantKey, string $reference): int
{
    $central = DB::connection('mysql');
    $now = Carbon::now();

    $id = (int) $central->table('invoices')->insertGetId([
        'tenant_id' => $tenantKey,
        'reference' => $reference,
        'period' => 'Aug 2026',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'total_minor' => 171_000_00,
        'currency' => 'JMD',
        'due_on' => '2026-08-15',
        'status' => 'issued',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $central->table('invoice_lines')->insert([
        [
            'invoice_id' => $id, 'description' => 'Premium subscription', 'quantity' => 450,
            'unit_price_minor' => 340_00, 'total_minor' => 153_000_00, 'currency' => 'JMD',
            'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'invoice_id' => $id, 'description' => 'Security Provider add-on', 'quantity' => 6,
            'unit_price_minor' => 3_000_00, 'total_minor' => 18_000_00, 'currency' => 'JMD',
            'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    return $id;
}

it('shows this estate its own invoice, whole, and 404s every other estate\'s', function () {
    $own = estateInvoice(FacilitiesFixture::ESTATE, 'FT-INV-TEST-0826');

    $otherTenant = (string) (DB::connection('mysql')->table('tenants')
        ->where('id', '!=', FacilitiesFixture::ESTATE)
        ->value('id') ?? 'someotherestate');

    $theirs = estateInvoice($otherTenant, 'OT-INV-TEST-0826');

    DB::connection('mysql')->table('credit_notes')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'invoice_id' => $own,
        'reference' => 'FT-INV-TEST-0826-CN1',
        'amount_minor' => 1_000_00,
        'reason' => 'Two units billed before handover',
        'issued_on' => '2026-08-20',
        'issued_by_id' => null,
        'issued_by_name' => 'Gemini Accounts',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Settings/BillingInvoice')
            ->where('invoice.number', 'INV-2026-08')
            ->where('invoice.reference', 'FT-INV-TEST-0826')
            ->has('invoice.lines', 2)
            ->where('invoice.lines.0.is_subscription', true)
            ->where('invoice.lines.1.is_subscription', false)
            ->where('invoice.total', '$171,000.00')
            ->where('invoice.subscription_total', '$153,000.00')
            ->where('invoice.credited', '$1,000.00')
            ->where('invoice.payable', '$170,000.00')
            ->has('invoice.notes', 1));

    // ANOTHER CLIENT'S INVOICE IS A 404, not a 403 that confirms it exists.
    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$theirs))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$theirs.'/pdf'))
        ->assertNotFound();

    // What the estate pays is a Settings read; a role without Settings is refused.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own))
        ->assertForbidden();

    // The PDF is an export, and every export is on the record.
    $before = DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count();

    $response = $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/billing/invoices/'.$own.'/pdf'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count())->toBe($before + 1);
});
