<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\DunningTemplateDraft;
use App\Models\Role;
use App\Services\Estate\Collections;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The dunning template editor — board 8 (12 §1)
|--------------------------------------------------------------------------
|
| THE RULING, in three parts. A new or edited stage saves as a DRAFT and the
| estate keeps sending what it was sending. Putting one in force needs a
| committee resolution reference. And a notice already sent is never altered,
| whatever happens to the template it came from.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The ladder and the log live in `EstateFinanceSeeder`, which the facilities
    // fixture does not run — this suite is about the ladder, so it runs it.
    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('saves a reworded step as a draft, and the estate goes on sending what it was sending', function () {
    // Entry on Dues & ledger words a step; the Treasurer is its approver.
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $step = DunningTemplate::query()->where('is_active', true)->orderBy('stage')->firstOrFail();
    $wordingInForce = $step->body;

    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/finance/dunning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSend', true)
            ->where('canAdopt', false)
            ->has('channels')
            ->where('drafts', []));

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id,
            'label' => $step->label,
            'stage' => $step->stage,
            'channel' => 'push+email',
            'subject' => 'Your maintenance account, {unit_label}',
            'body' => "Dear {resident_first_name},\n\n{unit_label} is {days_overdue} days past due at {amount_due}.",
            'days_overdue' => $step->days_overdue,
        ])
        ->assertRedirect(FacilitiesFixture::url('/finance/dunning'));

    FacilitiesFixture::boot();

    $draft = DunningTemplateDraft::query()->sole();

    // THE LADDER IN FORCE HAS NOT MOVED.
    expect(DunningTemplate::query()->findOrFail($step->id)->body)->toBe($wordingInForce)
        ->and($draft->dunning_template_id)->toBe($step->id)
        ->and($draft->resolution_reference)->toBeNull()
        ->and($draft->adopted_at)->toBeNull()
        ->and($draft->drafted_by_name)->toBe($assistant->name);

    // A second save against the same step rewrites the one draft, rather than
    // putting two proposals for one rung in front of the committee.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id,
            'label' => $step->label,
            'stage' => $step->stage,
            'channel' => 'email',
            'subject' => 'Second thoughts',
            'body' => 'Dear {resident_first_name}, please call the office.',
            'days_overdue' => $step->days_overdue,
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(DunningTemplateDraft::query()->count())->toBe(1)
        ->and(DunningTemplateDraft::query()->sole()->subject)->toBe('Second thoughts');

    // A token this estate cannot fill would arrive on a phone as its own text.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id,
            'label' => $step->label,
            'stage' => $step->stage,
            'channel' => 'email',
            'subject' => 'Hello',
            'body' => 'Dear {resident_first_name}, your {strata_lot_number} is overdue.',
            'days_overdue' => 0,
        ])
        ->assertSessionHasErrors('body');

    // A reader of the log words nothing.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts'), [
            'template_id' => $step->id, 'label' => 'x', 'stage' => 1,
            'channel' => 'email', 'subject' => 'x', 'body' => 'x', 'days_overdue' => 0,
        ])
        ->assertForbidden();
});

it('puts a draft in force only against a committee resolution, and never alters a notice already sent', function () {
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $step = DunningTemplate::query()->where('is_active', true)->orderBy('stage')->firstOrFail();

    // A notice that went out under the wording in force. This is the row the
    // whole log exists for, and nothing below may touch it.
    $sent = DunningNotice::query()->create([
        'unit_id' => FacilitiesFixture::unit('Lot 9')->id,
        'dunning_template_id' => $step->id,
        'template_label' => $step->label,
        'stage' => $step->stage,
        'channel' => $step->channel,
        'subject' => 'As it was sent',
        'body' => 'This is the wording the resident actually received.',
        'sent_at' => now()->subDay(),
        'delivery_state' => 'delivered',
    ]);

    app(Collections::class)->saveDraft([
        'label' => 'Final demand',
        'stage' => 9,
        'channel' => 'push+email+sms',
        'subject' => 'Final demand — {unit_label}',
        'body' => 'Dear {resident_first_name}, {amount_due} remains unpaid {days_overdue} days after {due_date}.',
        'days_overdue' => 90,
    ], $assistant);

    FacilitiesFixture::boot();

    $draft = DunningTemplateDraft::query()->whereNull('dunning_template_id')->sole();

    // The office words it. Putting it in force is the approver's act.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-14'])
        ->assertForbidden();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => ''])
        ->assertSessionHasErrors('resolution_reference');

    FacilitiesFixture::boot();

    expect(DunningTemplate::query()->where('label', 'Final demand')->exists())->toBeFalse();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-14'])
        ->assertRedirect(FacilitiesFixture::url('/finance/dunning'));

    FacilitiesFixture::boot();

    $live = DunningTemplate::query()->where('label', 'Final demand')->sole();
    $draft = DunningTemplateDraft::query()->findOrFail($draft->id);

    expect($live->stage)->toBe(9)
        ->and($live->days_overdue)->toBe(90)
        ->and($live->is_active)->toBeTrue()
        ->and($draft->adopted_at)->not->toBeNull()
        ->and($draft->resolution_reference)->toBe('Res. 2026-14')
        ->and($draft->dunning_template_id)->toBe($live->id)
        ->and($draft->adopted_by_name)->toBe($treasurer->name);

    // THE SENT NOTICE IS UNTOUCHED, subject and body exactly as they went out.
    $sent = DunningNotice::query()->findOrFail($sent->id);

    expect($sent->subject)->toBe('As it was sent')
        ->and($sent->body)->toBe('This is the wording the resident actually received.');

    // An adopted draft is decided, so it leaves the waiting list — and it
    // cannot be put in force a second time.
    expect(collect(app(Collections::class)->draftBoard())->contains('id', $draft->id))->toBeFalse();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-15'])
        ->assertSessionHasErrors('resolution_reference');
});
