<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Estate\AccountingController;
use App\Http\Controllers\Estate\CollectionsController;
use App\Http\Controllers\Estate\DashboardController as EstateDashboardController;
use App\Http\Controllers\Estate\DuesController;
use App\Http\Controllers\Estate\EstateStructureController;
use App\Http\Controllers\Estate\FacilitiesController;
use App\Http\Controllers\Estate\GovernanceController;
use App\Http\Controllers\Estate\NoticesController;
use App\Http\Controllers\Estate\PayablesController;
use App\Http\Controllers\Estate\PayrollController;
use App\Http\Controllers\Estate\RecordController;
use App\Http\Controllers\Estate\ReportsController;
use App\Http\Controllers\Estate\ResidentsController;
use App\Http\Controllers\Estate\SettingsController;
use App\Http\Middleware\EnsureEstateAccess;
use App\Http\Middleware\ForgetTenantRouteParameter;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Estate Console routes
|--------------------------------------------------------------------------
|
| The SAME routes, reachable two ways.
|
|   Production:  phoenixpark.geminisecure.com/...     (subdomain)
|   Local:       localhost:8000/estate/phoenixpark/...  (path)
|
| Subdomains are right for production — they give each community its own
| hostname and let the session cookie be scoped per estate. They are wrong for
| local development: *.localhost does not resolve on Windows, a hosts entry
| needs administrator rights, and a second registrable domain means the session
| cookie does not travel, which is a login loop rather than a login screen.
|
| So the path form exists ONLY in local, and both forms register the same
| controllers. Nothing about the application knows which one it is serving.
|
| Tenant identity comes from the resolved subdomain or path segment and from
| nowhere else. It is never read from a request parameter, hidden field or
| query string.
|
*/

/** The routes themselves, registered identically under both resolvers. */
$estateRoutes = function (): void {
    Route::get('/', EstateDashboardController::class)->name('estate.home');

    /*
     * Estate structure — board 3.
     *
     * READING THE LAYOUT IS `view`; CHANGING IT IS NOT. A phase brings addresses
     * into existence, and an address is what a household is filed at, what dues
     * are billed to and what a guard admits somebody to — so it is `create`,
     * which the President and the Vice President do not hold. Board 24 gives
     * this module Full to the Community Super Admin and the Property Manager,
     * View to the three officers, and an em dash to the Treasurer and the Admin
     * Assistant: a Treasurer opening this console finds no Estate structure at
     * all, whatever every estate board draws in its sidebar (D-044).
     */
    Route::get('estate', [EstateStructureController::class, 'index'])
        ->middleware('can:estate.estate_structure.view')
        ->name('estate.structure');

    // Both writes are `create` (12 §2, Wave 1) and both are all-or-nothing:
    // a phase with its lots in one transaction, an import committed whole.
    Route::middleware('can:estate.estate_structure.create')->group(function (): void {
        Route::post('estate/phases', [EstateStructureController::class, 'addPhase'])->name('estate.structure.phase.add');

        Route::post('estate/import/preview', [EstateStructureController::class, 'previewImport'])->name('estate.structure.import.preview');

        Route::post('estate/import/commit', [EstateStructureController::class, 'commitImport'])->name('estate.structure.import.commit');
    });

    /*
     * Residents — boards 4, 31, 34 and 38.
     *
     * READING THE REGISTER IS `view` AND EVERY ESTATE ROLE HOLDS IT. What they
     * do not all hold is what a register READS: a household's balance is
     * `estate.dues_ledger.view`, a different module that the Property Manager is
     * locked out of by platform invariant (D-010). The controller decides that
     * once and passes it down; there is no residents permission that grants a
     * resident's financial position.
     *
     * ADDING A RESIDENT IS `create`. It puts a person on the estate's register,
     * which is where authorisation at a gate ultimately comes from.
     *
     * APPROVING A CLAIM IS `approve`, ON ITS OWN, AND `update` DOES NOT REACH
     * IT. Approving binds a person to a household — whose guest passes they may
     * issue, whose gate they may be admitted at — and no edit afterwards unbinds
     * the night somebody was let through. Refusing a claim and asking for a
     * document are `update`, because each is answered by making the opposite
     * decision and neither authorises anybody. That split is D-013's whole
     * purpose, and it is why the Secretary — who holds Full on Residents and
     * therefore `update` — is refused the approval and not the refusal.
     */
    Route::middleware('can:estate.residents.view')
        ->prefix('residents')
        ->name('estate.residents.')
        ->group(function (): void {
            Route::get('/', [ResidentsController::class, 'index'])->name('index');

            /*
             * BOTH LITERALS BEFORE `{unit}`, and it is not a preference. The
             * detail route matches any lowercase slug, so "claims" and "new"
             * would both resolve to a unit lookup and answer 404 if they were
             * registered after it.
             */
            Route::get('claims', [ResidentsController::class, 'claims'])->name('claims');

            Route::get('new', [ResidentsController::class, 'create'])->name('new');

            Route::post('/', [ResidentsController::class, 'store'])
                ->middleware('can:estate.residents.create')
                ->name('store');

            Route::post('claims/{claim}/approve', [ResidentsController::class, 'approveClaim'])
                ->whereNumber('claim')
                ->middleware('can:estate.residents.approve')
                ->name('claim.approve');

            Route::post('claims/{claim}/reject', [ResidentsController::class, 'rejectClaim'])
                ->whereNumber('claim')
                ->middleware('can:estate.residents.update')
                ->name('claim.reject');

            Route::post('claims/{claim}/document', [ResidentsController::class, 'requestDocument'])
                ->whereNumber('claim')
                ->middleware('can:estate.residents.update')
                ->name('claim.document');

            /*
             * Board 38's own URL keys on the LOT and not on the person — the
             * unit outlives every household in it, so a bookmark still resolves
             * after the family at Lot 47 has moved out.
             */
            Route::get('{unit}', [ResidentsController::class, 'show'])
                ->where('unit', '[a-z0-9][a-z0-9-]*')
                ->name('show');

            /*
             * Correcting a person's record is `update` (12 §2, Wave 2). It does
             * NOT change whether they are authorised — that stays with the
             * claims queue, where the decision has a name and a date on it.
             */
            Route::post('{unit}/people/{resident}', [ResidentsController::class, 'editResident'])
                ->where('unit', '[a-z0-9][a-z0-9-]*')
                ->whereNumber('resident')
                ->middleware('can:estate.residents.update')
                ->name('person.edit');
        });

    /*
     * Dues & ledger — boards 5, 6 and 35.
     *
     * Reading the arrears is `view`. POSTING A CHARGE IS NOT: it adds to what a
     * household owes, so it needs `create`, and the Property Manager holds
     * neither by platform invariant rather than estate preference (D-010).
     */
    Route::middleware('can:estate.dues_ledger.view')
        ->prefix('finance')
        ->name('estate.dues.')
        ->group(function (): void {
            Route::get('arrears', [DuesController::class, 'arrears'])->name('arrears');

            Route::get('units/{unit}', [DuesController::class, 'unit'])
                ->whereNumber('unit')
                ->name('unit');

            /*
             * HARDSHIP AND DISPUTE (12 §1). A flag records a committee decision
             * to stop chasing a household, carries the minute that agreed it,
             * and moves no money — so it is `approve`, not the office's
             * `create`. Lifting one is the same decision in reverse.
             */
            Route::post('units/{unit}/flag', [DuesController::class, 'flagUnit'])
                ->whereNumber('unit')
                ->middleware('can:estate.dues_ledger.approve')
                ->name('unit.flag');

            Route::post('units/{unit}/flag/{flag}/lift', [DuesController::class, 'liftFlag'])
                ->whereNumber('unit')
                ->whereNumber('flag')
                ->middleware('can:estate.dues_ledger.approve')
                ->name('unit.flag.lift');

            /*
             * RECORDING A PAYMENT IS THE PAYMENTS MODULE'S VERB, not this one's.
             * D-010 split `payments` from `dues_ledger` so a role may read what a
             * household owes without being able to credit it; the Treasurer and
             * the Admin Assistant hold both, and board 6 draws the control for
             * the Treasurer. Dr 1000 Bank, Cr 1200 against the unit.
             */
            Route::post('units/{unit}/payments', [DuesController::class, 'recordPayment'])
                ->whereNumber('unit')
                ->middleware('can:estate.payments.create')
                ->name('unit.payment');

            // The receipt register — board 5's Receipts tab, with the sequence
            // the ruling gave it and every gap in it drawn.
            Route::get('receipts', [DuesController::class, 'receipts'])->name('receipts');

            Route::get('charges/new', [DuesController::class, 'newCharge'])->name('charge.new');

            Route::post('charges', [DuesController::class, 'postCharge'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.store');

            /*
             * A WHOLE-PHASE OR WHOLE-ESTATE CHARGE IS TWO PRESSES (12 §2).
             * The first draws the list and posts nothing; the second bills
             * every unit on that list in one transaction, all of them or none.
             * Same `create` gate as a single charge — it is the same act, at
             * the scale that makes the preview necessary.
             */
            Route::post('charges/preview', [DuesController::class, 'previewBulk'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.preview');

            Route::post('charges/bulk', [DuesController::class, 'postBulk'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.bulk');

            /*
             * Collections — boards 7 and 8.
             *
             * Same module, same `view` gate on the reads, and TWO different
             * write gates on the writes. Drafting a plan, recording that a
             * household agreed and sending a notice are `create`. ACTIVATING a
             * plan is `approve`, on its own, because it lifts the arrears
             * restriction on a household's guest passes — a decision about who
             * gets through the gate tonight, not data entry, and `approve` is
             * the verb this system reserves for acts that editing the row
             * afterwards cannot walk back (D-013).
             */
            Route::get('units/{unit}/payment-plan', [CollectionsController::class, 'plan'])
                ->whereNumber('unit')
                ->name('plan');

            Route::post('units/{unit}/payment-plan', [CollectionsController::class, 'generate'])
                ->whereNumber('unit')
                ->middleware('can:estate.dues_ledger.create')
                ->name('plan.generate');

            Route::post('payment-plans/{plan}/agree', [CollectionsController::class, 'agree'])
                ->whereNumber('plan')
                ->middleware('can:estate.dues_ledger.create')
                ->name('plan.agree');

            Route::post('payment-plans/{plan}/activate', [CollectionsController::class, 'activate'])
                ->whereNumber('plan')
                ->middleware('can:estate.dues_ledger.approve')
                ->name('plan.activate');

            Route::get('dunning', [CollectionsController::class, 'dunning'])->name('dunning');

            Route::post('dunning', [CollectionsController::class, 'send'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('dunning.send');

            /*
             * The template editor (12 §1). Wording a step is `create` — the
             * office proposes, and a draft sends nothing. Putting one in force
             * is `approve`, against a committee resolution reference, because
             * what a household is told about its debt is the committee's
             * decision and not the office's.
             */
            Route::post('dunning/drafts', [CollectionsController::class, 'saveDraft'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('dunning.draft');

            Route::post('dunning/drafts/{draft}/adopt', [CollectionsController::class, 'adoptDraft'])
                ->whereNumber('draft')
                ->middleware('can:estate.dues_ledger.approve')
                ->name('dunning.adopt');
        });

    /*
     * Accounting — boards 25 to 28, and 39 behind them.
     *
     * A separate permission module from Dues & ledger, and deliberately: D-010
     * split them so a Property Manager could be refused the estate's books
     * while still seeing the vendor costs they commission.
     *
     * READING IS `view`; the writes below are not. Paying a bill moves money out
     * of the estate's bank account, so it needs `create`; approving a bill and
     * completing a reconciliation both need `approve`, because each is the
     * moment a figure stops being a proposal — an approved bill is money owed,
     * and a signed-off period is one nobody re-opens. D-013 separated `approve`
     * from `update` for exactly that.
     */
    Route::middleware('can:estate.accounting_posting.view')
        ->prefix('accounting')
        ->name('estate.accounting.')
        ->group(function (): void {
            Route::get('chart-of-accounts', [AccountingController::class, 'chart'])->name('chart');

            /*
             * CHANGING THE CHART IS `configure` (12 §2, Wave 1). Adding an
             * account changes what every future entry may post against, and
             * archiving one closes it to every future entry; neither is data
             * entry, so the Admin Assistant's Entry cell does not reach them.
             * There is no delete route and never will be — an account with
             * history is archived, and the database refuses the alternative.
             */
            Route::post('chart-of-accounts', [AccountingController::class, 'addAccount'])
                ->middleware('can:estate.accounting_posting.configure')
                ->name('chart.add');

            Route::post('chart-of-accounts/{account}/archive', [AccountingController::class, 'archiveAccount'])
                ->whereNumber('account')
                ->middleware('can:estate.accounting_posting.configure')
                ->name('chart.archive');

            Route::get('vendors', [PayablesController::class, 'vendors'])->name('vendors');

            // Adding a supplier and recording a bill are `create`: they bring a
            // row into existence and post nothing. Approval posts, and is `approve`.
            Route::post('vendors', [PayablesController::class, 'addVendor'])
                ->middleware('can:estate.accounting_posting.create')
                ->name('vendor.add');

            Route::post('bills', [PayablesController::class, 'recordBill'])
                ->middleware('can:estate.accounting_posting.create')
                ->name('bill.record');

            Route::get('vendors/{vendor}', [PayablesController::class, 'vendor'])
                ->whereNumber('vendor')
                ->name('vendor');

            // Editing changes who the estate may pay — `update`, not `create`
            // (12 §2, Wave 2). Deactivating is an edit; nothing deletes.
            Route::post('vendors/{vendor}', [PayablesController::class, 'editVendor'])
                ->whereNumber('vendor')
                ->middleware('can:estate.accounting_posting.update')
                ->name('vendor.edit');

            Route::get('bills', [PayablesController::class, 'bills'])->name('bills');

            Route::get('reconciliation', [PayablesController::class, 'reconciliation'])->name('reconciliation');

            Route::post('bills/{bill}/approve', [PayablesController::class, 'approveBill'])
                ->whereNumber('bill')
                ->middleware('can:estate.accounting_posting.approve')
                ->name('bill.approve');

            Route::post('bills/{bill}/pay', [PayablesController::class, 'payBill'])
                ->whereNumber('bill')
                ->middleware('can:estate.accounting_posting.create')
                ->name('bill.pay');

            Route::post('reconciliation/lines/{line}/match', [PayablesController::class, 'matchLine'])
                ->whereNumber('line')
                ->middleware('can:estate.accounting_posting.create')
                ->name('reconciliation.match');

            Route::post('reconciliation/lines/{line}/unmatch', [PayablesController::class, 'unmatchLine'])
                ->whereNumber('line')
                ->middleware('can:estate.accounting_posting.create')
                ->name('reconciliation.unmatch');

            Route::post('reconciliation/{reconciliation}/complete', [PayablesController::class, 'completeReconciliation'])
                ->whereNumber('reconciliation')
                ->middleware('can:estate.accounting_posting.approve')
                ->name('reconciliation.complete');
        });

    /*
     * Payroll & HR — boards 13, 14, 15, 16 and 37.
     *
     * THE PROPERTY MANAGER IS ON THIS PAYROLL AND CANNOT OPEN IT. Patricia
     * Morgan is board 15's first line and board 37's first row, and D-010 locks
     * her role out of `payroll` entirely. Being ON a payroll is not permission
     * to SEE one — least of all three colleagues' gross pay, their bank details
     * and their NIS numbers.
     *
     * CALCULATING IS `update`; APPROVING IS `approve`, AND THE SPLIT IS THE
     * WHOLE CONTROL. Board 15's banner says it in the estate's own words:
     * "Prepared by Tracey Reid — awaiting your approval. As a second approver,
     * this run cannot be disbursed until you review and approve it." The
     * permission is only half of that. The other half is that the preparer and
     * the approver must be two different people, which no middleware can
     * express and `Payroll::approvalRefusal()` therefore checks itself.
     *
     * FILING A RETURN IS `approve` TOO. It moves money to the revenue authority
     * and clears a liability; that is the same act as releasing a pay run, done
     * to a different creditor.
     */
    Route::middleware('can:estate.payroll.view')
        ->prefix('payroll')
        ->name('estate.payroll.')
        ->group(function (): void {
            Route::get('/', [PayrollController::class, 'runs'])->name('runs');

            // Starting the next run on the calendar brings a draft into
            // existence and posts nothing: `create`.
            Route::post('runs', [PayrollController::class, 'startRun'])
                ->middleware('can:estate.payroll.create')
                ->name('run.start');

            Route::get('employees', [PayrollController::class, 'employees'])->name('employees');

            Route::get('filings', [PayrollController::class, 'filings'])->name('filings');

            /*
             * Bound on the period slug, and registered AFTER the two literals
             * above so "employees" and "filings" cannot be swallowed as run
             * periods. The pattern excludes a leading digit for the same reason
             * it is belt and braces: a slug is "aug-2026", never a number.
             */
            Route::get('runs/{slug}', [PayrollController::class, 'show'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->name('run');

            Route::get('runs/{slug}/exceptions', [PayrollController::class, 'exceptions'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->name('run.exceptions');

            Route::post('runs/{slug}/calculate', [PayrollController::class, 'calculate'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.update')
                ->name('run.calculate');

            Route::post('runs/{slug}/changes', [PayrollController::class, 'requestChanges'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.update')
                ->name('run.changes');

            // The irreversible one: money leaves the bank.
            Route::post('runs/{slug}/approve', [PayrollController::class, 'approve'])
                ->where('slug', '[a-z][a-z0-9-]*')
                ->middleware('can:estate.payroll.approve')
                ->name('run.approve');

            Route::post('exceptions/{exception}', [PayrollController::class, 'resolveException'])
                ->whereNumber('exception')
                ->middleware('can:estate.payroll.update')
                ->name('exception.resolve');

            Route::post('filings/{filing}/file', [PayrollController::class, 'file'])
                ->whereNumber('filing')
                ->middleware('can:estate.payroll.approve')
                ->name('filing.file');
        });

    /*
     * Facilities — boards 17, 18, 19 and 20.
     *
     * READING IS `view`; triaging is not. Assigning a vendor, changing a
     * priority, resolving a ticket and deciding a booking all change what
     * somebody is asked to do outside this screen, so they need `update`;
     * raising a work order and blocking a slot bring something into existence,
     * so they need `create`.
     *
     * RECORDING A BOOKING FEE CARRIES A SECOND GATE, AND IT IS NOT A FACILITIES
     * ONE. It posts a charge against a unit — Dr 1200, Cr 4100 — which adds to
     * what a household owes, and that is `estate.dues_ledger.create` wherever it
     * happens. Board 19 names "record a fee" among the Property Manager's
     * actions and D-010 locks that role out of Dues & ledger entirely; the
     * platform invariant outranks the board, so the control is drawn inert for
     * them with the reason on it rather than quietly working.
     */
    Route::middleware('can:estate.facilities.view')
        ->prefix('facilities')
        ->name('estate.facilities.')
        ->group(function (): void {
            Route::get('maintenance', [FacilitiesController::class, 'maintenance'])->name('maintenance');

            // Raising a work order brings a job into existence and starts its
            // clock: `create` (12 §2, Wave 2).
            Route::post('maintenance', [FacilitiesController::class, 'raiseWorkOrder'])
                ->middleware('can:estate.facilities.create')
                ->name('ticket.raise');

            // Bound on the ticket NUMBER, which is what board 18's own URL uses
            // and what a bill already stores against the job.
            Route::get('maintenance/{ticket}', [FacilitiesController::class, 'ticket'])
                ->whereNumber('ticket')
                ->name('ticket');

            Route::post('maintenance/{ticket}/assign', [FacilitiesController::class, 'assignVendor'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.assign');

            Route::post('maintenance/{ticket}/priority', [FacilitiesController::class, 'changePriority'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.priority');

            Route::post('maintenance/{ticket}/resolve', [FacilitiesController::class, 'resolveTicket'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.resolve');

            Route::post('maintenance/{ticket}/reopen', [FacilitiesController::class, 'reopenTicket'])
                ->whereNumber('ticket')
                ->middleware('can:estate.facilities.update')
                ->name('ticket.reopen');

            Route::get('amenities/bookings', [FacilitiesController::class, 'bookings'])->name('bookings');

            Route::get('amenities/settings', [FacilitiesController::class, 'amenitySettings'])->name('amenities');

            // The rate card (12 §2, Wave 2): adding brings an amenity into
            // existence, `create`; editing changes what the NEXT booking copies
            // and never a confirmed one, `update`. Retiring is an edit.
            Route::post('amenities', [FacilitiesController::class, 'addAmenity'])
                ->middleware('can:estate.facilities.create')
                ->name('amenity.add');

            Route::post('amenities/{amenity}', [FacilitiesController::class, 'editAmenity'])
                ->whereNumber('amenity')
                ->middleware('can:estate.facilities.update')
                ->name('amenity.edit');

            Route::post('amenities/bookings/{booking}/approve', [FacilitiesController::class, 'approveBooking'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.approve');

            Route::post('amenities/bookings/{booking}/decline', [FacilitiesController::class, 'declineBooking'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.decline');

            Route::post('amenities/{amenity}/block', [FacilitiesController::class, 'blockSlot'])
                ->whereNumber('amenity')
                ->middleware('can:estate.facilities.create')
                ->name('amenity.block');

            Route::post('amenities/bookings/{booking}/fee', [FacilitiesController::class, 'recordBookingFee'])
                ->whereNumber('booking')
                ->middleware(['can:estate.facilities.update', 'can:estate.dues_ledger.create'])
                ->name('booking.fee');

            /*
             * THE BOOKING DETAIL AND THE DEPOSIT DOOR (D-086) — the one screen in
             * this module no approved board draws, added on the client's ruling:
             * "A service that moves money with no route is a worse gap than the
             * caution it replaced."
             *
             * Two gates, and the higher one keeps money. Taking a deposit and
             * refunding it are `update` — the estate's books catching up with
             * cash that has already moved. Forfeiting keeps a resident's money as
             * income, so it is `approve`, and it carries a required reason.
             * Deliberately NOT `accounting_posting`: the ruling made this the
             * Property Manager's screen, and D-010 locks that role out of
             * Accounting — a door carrying both would be one its own persona
             * could never open. `EstateFacilitiesAmenitiesTest` pins all three.
             */
            Route::get('amenities/bookings/{booking}', [FacilitiesController::class, 'booking'])
                ->whereNumber('booking')
                ->name('booking');

            Route::post('amenities/bookings/{booking}/deposit/hold', [FacilitiesController::class, 'holdDeposit'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.deposit.hold');

            Route::post('amenities/bookings/{booking}/deposit/refund', [FacilitiesController::class, 'refundDeposit'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.update')
                ->name('booking.deposit.refund');

            Route::post('amenities/bookings/{booking}/deposit/forfeit', [FacilitiesController::class, 'forfeitDeposit'])
                ->whereNumber('booking')
                ->middleware('can:estate.facilities.approve')
                ->name('booking.deposit.forfeit');
        });

    /*
     * Governance — boards 9, 10, 11, 12 and 36.
     *
     * Reading is `view`. Running the election — opening nominations, closing
     * them, opening and extending the poll, vetting a candidate — is `update`,
     * which the Secretary holds.
     *
     * CERTIFYING AND PUBLISHING ARE `approve`, AND THE SECRETARY DOES NOT HOLD
     * IT. The estate matrix gives Governance `Full · Approver` to the President
     * and Vice President and plain `Full` to the Secretary, so the officer who
     * RUNS an election is deliberately not the officer who declares the result
     * final. Both acts are irreversible — a certified ballot cannot be reopened,
     * and a published meeting has already told 450 households a date — which is
     * exactly what D-013 separated `approve` from `update` for.
     *
     * THERE IS NO ROUTE THAT CASTS A VOTE. Voting is a resident act in the
     * resident app; this console runs the election and never marks a paper.
     */
    Route::middleware('can:estate.governance.view')
        ->prefix('governance')
        ->name('estate.governance.')
        ->group(function (): void {
            /*
             * Notices — board 32.
             *
             * POSTING IS `create`, NOT `approve`. Publishing a meeting commits
             * every household to a date they will arrange a Saturday around;
             * a notice tells them something and asks nothing of them. Gating a
             * gate closure behind the President would mean the Property Manager
             * who closed the gate could not say so.
             */
            Route::get('notices', [NoticesController::class, 'index'])->name('notices');

            Route::post('notices', [NoticesController::class, 'store'])
                ->middleware('can:estate.governance.create')
                ->name('notice.store');

            Route::get('elections/{year}', [GovernanceController::class, 'controlRoom'])
                ->whereNumber('year')
                ->name('election');

            Route::get('elections/{year}/nominations', [GovernanceController::class, 'nominations'])
                ->whereNumber('year')
                ->name('nominations');

            Route::get('elections/{year}/results', [GovernanceController::class, 'results'])
                ->whereNumber('year')
                ->name('results');

            /*
             * Ahead of `meetings/{meeting}` in every sense that matters — there
             * is no such route today, and registering the literal first means
             * adding one later cannot swallow "new" as an id.
             */
            Route::get('meetings/new', [GovernanceController::class, 'newMeeting'])->name('meeting.new');

            Route::get('meetings', [GovernanceController::class, 'meetings'])->name('meetings');

            Route::post('ballots/{ballot}/close-nominations', [GovernanceController::class, 'closeNominations'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.close_nominations');

            Route::post('ballots/{ballot}/open', [GovernanceController::class, 'openVoting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.open');

            Route::post('ballots/{ballot}/close', [GovernanceController::class, 'closeVoting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.close');

            Route::post('ballots/{ballot}/extend', [GovernanceController::class, 'extendVoting'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.update')
                ->name('ballot.extend');

            // The irreversible one.
            Route::post('ballots/{ballot}/certify', [GovernanceController::class, 'certify'])
                ->whereNumber('ballot')
                ->middleware('can:estate.governance.approve')
                ->name('ballot.certify');

            Route::post('nominations/{nomination}/accept', [GovernanceController::class, 'acceptNomination'])
                ->whereNumber('nomination')
                ->middleware('can:estate.governance.update')
                ->name('nomination.accept');

            Route::post('nominations/{nomination}/reject', [GovernanceController::class, 'rejectNomination'])
                ->whereNumber('nomination')
                ->middleware('can:estate.governance.update')
                ->name('nomination.reject');

            Route::post('nominations/{nomination}/query', [GovernanceController::class, 'queryNomination'])
                ->whereNumber('nomination')
                ->middleware('can:estate.governance.update')
                ->name('nomination.query');

            Route::post('meetings', [GovernanceController::class, 'storeMeeting'])
                ->middleware('can:estate.governance.create')
                ->name('meeting.store');

            // Refused inside the statutory notice period. See Governance.
            Route::post('meetings/{meeting}/publish', [GovernanceController::class, 'publishMeeting'])
                ->whereNumber('meeting')
                ->middleware('can:estate.governance.approve')
                ->name('meeting.publish');
        });

    /*
     * Reports — board 29.
     *
     * ONE ROUTE, `view`, AND NOTHING TO POST TO. The board draws a catalogue of
     * seven reports and seven "Generate" controls, and not one of them generates
     * yet — three of the seven have no data on this platform to generate from
     * (there is no budget, no estate-side incident record and no document
     * store). The controller says which is which, on each card, in the estate's
     * own terms.
     *
     * SO THERE IS DELIBERATELY NO `POST` HERE. A route that answered a Generate
     * with nothing would be worse than a control that says what it is waiting
     * for, and registering one now would fix the verb before the first report
     * exists to argue about it. When one lands it is `estate.reports.export` —
     * the payload already carries that gate for the page to draw with.
     *
     * THE SIDEBAR ITEM FLIPS IN THIS SAME CHANGE. `EstateNavigation` draws a
     * module with no screens as greyed and inert, and its own docblock requires
     * the href to be set in the commit that lands the module's first screen;
     * leaving it null here would tell a President the module is missing while
     * its page sits one click away.
     */
    Route::get('reports', [ReportsController::class, 'index'])
        ->middleware('can:estate.reports.view')
        ->name('estate.reports');

    /*
     * Settings — boards 21, 22, 23 and 24.
     *
     * READING IS `view`, and the matrix gives that to three roles only: Full to
     * the Community Super Admin, View to the President and the Vice President,
     * and nothing to the Secretary, Property Manager, Treasurer or Admin
     * Assistant. Those four get 403 on every route below, which is what the
     * Settings row of board 24 means when it draws them all as em dashes.
     *
     * SAVING THE PROFILE IS `update`; CHANGING A FEATURE IS `configure`. The
     * second is deliberately the stronger of the two: an estate's contact email
     * is a line on a notice, while switching the accounting module off changes
     * what several hundred households can do tomorrow. `configure` is one of
     * the verbs `AccessLevel::Full` grants and `Entry` does not, which is
     * exactly the distinction "data entry, no approval" is meant to draw.
     *
     * THERE IS NO ROUTE HERE THAT WRITES `role_module_access`, AND THAT IS THE
     * POINT. Board 24 draws the matrix and draws no control that changes a
     * cell. Changing what a role may do is an `approve`-level act (D-013) and
     * no estate role holds `estate.settings.approve` — the Community Super
     * Admin's Full cell carries no Approver tag, and D-008 is explicit that
     * Full without the tag does not grant it. So a settings screen cannot
     * become a privilege-escalation route: there is nothing to post to, the
     * service has no method that would write it, and the payload tells every
     * viewer the matrix is read-only. `EstateSettingsTest` proves all three
     * across all seven roles rather than assuming any of them.
     */
    Route::middleware('can:estate.settings.view')
        ->prefix('settings')
        ->name('estate.settings.')
        ->group(function (): void {
            Route::get('profile', [SettingsController::class, 'profile'])->name('profile');

            Route::get('users', [SettingsController::class, 'users'])->name('users');

            /*
             * INVITING IS `create` (12 §2, Wave 1): it brings an account into
             * existence. The matrix gives Settings create to the Community
             * Super Admin alone; the two officers read this screen and may not
             * issue a credential from it. Resend and revoke are the same act's
             * afterlife and carry the same gate.
             */
            Route::post('users/invitations', [SettingsController::class, 'invite'])
                ->middleware('can:estate.settings.create')
                ->name('users.invite');

            Route::post('users/invitations/{invitation}/resend', [SettingsController::class, 'resendInvitation'])
                ->whereNumber('invitation')
                ->middleware('can:estate.settings.create')
                ->name('users.invitation.resend');

            Route::post('users/invitations/{invitation}/revoke', [SettingsController::class, 'revokeInvitation'])
                ->whereNumber('invitation')
                ->middleware('can:estate.settings.create')
                ->name('users.invitation.revoke');

            Route::get('features', [SettingsController::class, 'features'])->name('features');

            Route::get('roles', [SettingsController::class, 'roles'])->name('roles');

            Route::get('notifications', [SettingsController::class, 'notifications'])->name('notifications');

            /*
             * READ-ONLY, BOTH OF THEM, AND FOR DIFFERENT REASONS.
             *
             * Data & privacy states policy the estate has been told rather than
             * policy it sets here — a retention period, who may export a
             * resident list, what Gemini receives under the security grant. None
             * of it has an owner on this platform yet, and inventing an edit
             * path for a rule nobody has been asked to set is worse than a
             * screen that says what the rule is.
             *
             * Billing reads a CENTRAL subscription. It is Gemini's commercial
             * record of this client, and an estate editing its own plan from its
             * own console is not a settings screen, it is a discount button.
             */
            Route::get('privacy', [SettingsController::class, 'privacy'])->name('privacy');

            Route::get('billing', [SettingsController::class, 'billing'])->name('billing');

            Route::post('notifications', [SettingsController::class, 'saveNotifications'])
                ->middleware('can:estate.settings.update')
                ->name('notifications.save');

            Route::post('profile', [SettingsController::class, 'saveProfile'])
                ->middleware('can:estate.settings.update')
                ->name('profile.save');

            /*
             * Bound on the catalogue's own feature KEY — "accounting_core",
             * "estate_payroll" — because that is what the central
             * `package_features` row is identified by and what an estate's
             * override stores. Constrained to the shape a key actually takes so
             * a path segment cannot arrive as anything else.
             */
            Route::post('features/{feature}', [SettingsController::class, 'updateFeature'])
                ->where('feature', '[a-z][a-z0-9_]{2,63}')
                ->middleware('can:estate.settings.configure')
                ->name('feature.update');
        });

    Route::prefix('records')
        ->name('estate.records.')
        ->whereNumber('id')
        ->group(function () {
            Route::get('residents/{id}', [RecordController::class, 'resident'])->name('resident');
            Route::get('households/{id}', [RecordController::class, 'household'])->name('household');
            Route::get('units/{id}', [RecordController::class, 'unit'])->name('unit');
            Route::get('charges/{id}', [RecordController::class, 'charge'])->name('charge');
            Route::get('journals/{id}', [RecordController::class, 'journal'])->name('journal');
        });
};

/**
 * The estate's own sign-in door — board 1.
 *
 * OUTSIDE `$estateRoutes` BECAUSE A GUEST IS EXACTLY WHO NEEDS IT. Every route
 * in that group carries `auth` and `EnsureEstateAccess`; a login screen behind
 * `auth` redirects to itself, which is a loop rather than a page.
 *
 * IT STILL RESOLVES TENANCY, and that is the whole point of a separate estate
 * door. The card names the community — "Phoenix Park Village 1 · Powered by
 * Gemini Security Limited" — and a resident arriving at their own estate's
 * address should not be shown a console they have never heard of. Resolving the
 * tenant here reads the subdomain or the path segment and nothing else: the
 * identity is never taken from a query string or a hidden field, which is the
 * same rule the authenticated routes follow.
 *
 * NAMED `estate.login`, NOT `login`. Two registrations sharing the name would
 * make `route('login')` ambiguous — Laravel keeps one entry per name — and the
 * central door is the one the framework's own `auth` middleware redirects to.
 */
$estateLoginRoute = function (): void {
    Route::get('login', [LoginController::class, 'create'])
        ->middleware('guest')
        ->name('estate.login');

    /*
     * Forgot password on the estate's own door (12 §2, Wave 1) — the same
     * controller as the central door, resolving the tenant the same way the
     * sign-in card does, so a committee member resets on the card that names
     * their community. Throttled like sign-in; one sentence whatever the
     * address.
     */
    Route::middleware('guest')->group(function (): void {
        Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('estate.password.request');

        Route::post('forgot-password', [PasswordResetController::class, 'send'])
            ->middleware('throttle:login')
            ->name('estate.password.email');

        Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('estate.password.reset');

        Route::post('reset-password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:login')
            ->name('estate.password.update');

        // Accepting an invitation, on the door of the estate it was sent for.
        Route::get('invitations/{token}', [InvitationController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->name('estate.invitation.show');

        Route::post('invitations/{token}', [InvitationController::class, 'accept'])
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:login')
            ->name('estate.invitation.accept');
    });
};

/*
 * PRODUCTION SHAPE: one hostname per estate.
 *
 * The group carries an explicit domain constraint. Without one these routes
 * match by URI on every host, so a bare "/" here would shadow the central
 * Gemini Console route — Laravel matches the URI first and only then runs the
 * middleware that rejects the host.
 */
Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
        'auth',
        EnsureEstateAccess::class,
    ])
    ->group($estateRoutes);

Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
    ])
    ->group($estateLoginRoute);

/*
 * LOCAL SHAPE: same host, estate in the path.
 *
 * Registered only in local. In every other environment an estate is reachable
 * by its own hostname and nothing else, so this cannot become a way around the
 * subdomain boundary in production.
 *
 * InitializeTenancyByPath requires {tenant} to be the FIRST route parameter,
 * which is why the prefix carries it directly.
 */
if (app()->isLocal()) {
    Route::prefix('estate/{tenant}')
        ->middleware([
            'web',
            InitializeTenancyByPath::class,
            'auth',
            EnsureEstateAccess::class,
        ])
        ->group($estateRoutes);

    Route::prefix('estate/{tenant}')
        ->middleware([
            'web',
            InitializeTenancyByPath::class,
        ])
        ->group($estateLoginRoute);
}
