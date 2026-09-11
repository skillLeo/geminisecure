<?php

declare(strict_types=1);

use App\Models\Estate\Employee;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Add employee — board 37, behind the consent box (12 §1)
|--------------------------------------------------------------------------
|
| THE CONSENT IS THE RULING AND IT IS NOT DECORATION. A bank account number
| and an NIS number are personal data the estate holds for seven years; the
| write is refused without the tick, and the officer who took it is recorded
| with the moment they did. The bank details themselves are optional — a
| register that refused to list somebody whose bank had not sent the account
| would understate the estate's wage bill.
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

    Employee::query()->whereIn('full_name', ['Delroy Simms', 'Marcia Reid'])->delete();
});

it('adds an employee only behind the consent box, and records who took it', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/payroll/employees'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/payroll/employees'), [
            'full_name' => 'Nobody', 'job_title' => 'x', 'employment_type' => 'full_time',
            'monthly_rate' => '1.00', 'consent' => true,
        ])
        ->assertForbidden();

    // WITHOUT THE TICK, REFUSED — not accepted with a warning.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/payroll/employees'), [
            'full_name' => 'Delroy Simms',
            'job_title' => 'Groundsman',
            'employment_type' => 'full_time',
            'monthly_rate' => '95000.00',
            'bank_name' => 'NCB',
            'bank_account_number' => '004417733315',
            'nis_number' => '1234567',
        ])
        ->assertSessionHasErrors('consent');

    FacilitiesFixture::boot();

    expect(Employee::query()->where('full_name', 'Delroy Simms')->exists())->toBeFalse();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/payroll/employees'), [
            'full_name' => 'Delroy Simms',
            'job_title' => 'Groundsman',
            'employment_type' => 'full_time',
            'monthly_rate' => '95000.00',
            'employed_since' => now()->subMonth()->toDateString(),
            'bank_name' => 'NCB',
            'bank_account_number' => '004417733315',
            'nis_number' => '1234567',
            'consent' => '1',
        ])
        ->assertRedirect(FacilitiesFixture::url('/payroll/employees'));

    FacilitiesFixture::boot();

    $employee = Employee::query()->where('full_name', 'Delroy Simms')->sole();

    expect($employee->monthly_rate_minor)->toBe(95_000_00)
        ->and($employee->status)->toBe(Employee::ACTIVE)
        ->and($employee->maskedBankAccount())->toBe('NCB •••• 3315');

    /*
     * THE CONSENT IS AUDITED, with the officer and the moment — not a boolean
     * on the row that says "yes" forever and nothing about when. Audit rows are
     * central, so the read is pinned to `mysql`.
     */
    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'estate.employee_added')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull();

    $after = json_decode((string) $entry->after, true);

    expect($after['consent_taken_by'])->toBe($treasurer->name)
        ->and($after['holds_bank_details'])->toBeTrue()
        ->and($after['holds_nis'])->toBeTrue()
        ->and($after['consent_at'])->not->toBeEmpty();

    // The register masks at the boundary, so no full account reaches a browser.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/payroll/employees'))
        ->assertOk()
        ->assertDontSee('004417733315');

    // A second row for one person is two salaries and two sets of deductions.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/payroll/employees'), [
            'full_name' => 'delroy simms', 'job_title' => 'Groundsman', 'employment_type' => 'full_time',
            'monthly_rate' => '95000.00', 'consent' => '1',
        ])
        ->assertSessionHasErrors('full_name');

    // Half a bank account pays nobody.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/payroll/employees'), [
            'full_name' => 'Marcia Reid', 'job_title' => 'Cleaner', 'employment_type' => 'part_time',
            'monthly_rate' => '40000.00', 'bank_account_number' => '12345', 'consent' => '1',
        ])
        ->assertSessionHasErrors('full_name');
});
