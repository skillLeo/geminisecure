<?php

declare(strict_types=1);

use App\Http\Controllers\Estate\AccountingController;
use App\Http\Controllers\Estate\CollectionsController;
use App\Http\Controllers\Estate\DashboardController as EstateDashboardController;
use App\Http\Controllers\Estate\DuesController;
use App\Http\Controllers\Estate\EstateStructureController;
use App\Http\Controllers\Estate\FacilitiesController;
use App\Http\Controllers\Estate\GovernanceController;
use App\Http\Controllers\Estate\PayablesController;
use App\Http\Controllers\Estate\RecordController;
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

            Route::get('charges/new', [DuesController::class, 'newCharge'])->name('charge.new');

            Route::post('charges', [DuesController::class, 'postCharge'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.store');

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

            Route::get('vendors', [PayablesController::class, 'vendors'])->name('vendors');

            Route::get('vendors/{vendor}', [PayablesController::class, 'vendor'])
                ->whereNumber('vendor')
                ->name('vendor');

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

            Route::get('features', [SettingsController::class, 'features'])->name('features');

            Route::get('roles', [SettingsController::class, 'roles'])->name('roles');

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
}
