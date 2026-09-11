<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Services\Estate\Residents;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Residents, unit claims and the register — board screens 4, 31, 34 and 38.
 *
 * NO SCREEN HERE PRINTS A RESTRICTED HOUSEHOLD'S BALANCE. Boards 4 and 38 both
 * draw a figure where a household's standing goes and board 31 draws an ageing
 * bucket, and all three read it out of `Residents::standingOf()` — which returns
 * amber and D-025's wording for a restricted household and no amount, no bucket
 * and no number of days. One function, one place to audit; three payload
 * builders each deciding for themselves is three places to forget.
 *
 * WHETHER MONEY MAY BE SEEN IS DECIDED HERE AND PASSED DOWN. `estate.residents`
 * and `estate.dues_ledger` are different modules on purpose: the Property
 * Manager holds Full on the first and nothing at all on the second (D-010), so a
 * resident detail screen that printed a balance would be a side door into
 * exactly the financial position that ruling closes. The service is told the
 * answer rather than asking the container for it, because a service that read
 * the permission itself would be a second copy of the route matrix.
 *
 * APPROVING A CLAIM NEEDS `approve`, NOT `update` (D-013). It binds a person to
 * a household — whose guest passes they may issue, whose gate they may be
 * admitted at — and no edit afterwards unbinds the night somebody was let
 * through. Refusing a claim and asking for a document are both `update`: each is
 * reversible by making the opposite decision, and neither authorises anybody.
 * The gates are on the routes; a controller that checked them again would be a
 * second place to keep in step with the matrix.
 */
class ResidentsController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it is drawn on.
     */
    private const NO_MESSAGE_YET = 'Not built yet — a message to a household reaches a resident\'s phone and is kept as a record, so it needs a template, a delivery adapter and a retention rule before it needs a button.';

    private const NO_LEDGER_LINK_YET = 'Dues & ledger is a separate module. A role that may read this register is not thereby allowed a resident\'s financial position.';

    /** The register — board community-admin-04. */
    public function index(Request $request, Residents $residents): Response
    {
        return inertia('Estate/Residents/Index', [
            'estate' => ['name' => (string) tenant()->name],
            ...$residents->listBoard(
                maySeeMoney: $this->maySeeMoney($request),
                phase: $request->string('phase')->toString(),
                search: $request->string('q')->toString(),
            ),
            'canCreate' => $request->user()->can('estate.residents.create'),
            'canReview' => $request->user()->can('estate.residents.approve'),
            'blockedReason' => 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',
        ]);
    }

    /** Unit claim review — board community-admin-31. */
    public function claims(Request $request, Residents $residents): Response
    {
        return inertia('Estate/Residents/Claims', [
            'estate' => ['name' => (string) tenant()->name],
            ...$residents->claimsBoard($this->maySeeMoney($request)),

            /*
             * TWO FLAGS AND THEY ARE NOT THE SAME ONE. Approving binds a person
             * to a household and is `approve`; refusing one and asking for a
             * document are `update`. A screen that drew both off one flag would
             * hand the irreversible act to every role that holds the reversible
             * ones — which is the collapse D-013 exists to prevent.
             */
            'canApprove' => $request->user()->can('estate.residents.approve'),
            'canReview' => $request->user()->can('estate.residents.update'),
            'blockedReason' => 'Approving a claim binds a person to a household and needs Residents approve access. You are able to read this screen and to ask for a document.',
        ]);
    }

    /** Add resident — board community-admin-34. */
    public function create(Request $request, Residents $residents): Response
    {
        return inertia('Estate/Residents/New', [
            'estate' => ['name' => (string) tenant()->name],
            ...$residents->newResidentBoard(),
            'canCreate' => $request->user()->can('estate.residents.create'),
            'blockedReason' => 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',
        ]);
    }

    /** One household in full — board community-admin-38. */
    public function show(Request $request, string $unit, Residents $residents): Response
    {
        $found = $this->resolveUnit($unit);

        return inertia('Estate/Residents/Show', [
            'estate' => ['name' => (string) tenant()->name],
            ...$residents->residentBoard($found, $this->maySeeMoney($request)),

            /*
             * The "View ledger" action is drawn only for a role that holds the
             * ledger. Absent rather than disabled, exactly as the sidebar
             * treats a module a role cannot reach — a greyed link to a
             * resident's financial position still tells somebody there is one.
             */
            'canViewLedger' => $this->maySeeMoney($request),
            'canEdit' => $request->user()->can('estate.residents.update'),
            'editBlockedReason' => 'Correcting a resident\'s record changes what the estate holds about a person, so it needs Residents update access. You are able to read this household.',
            'reasons' => [
                'message' => self::NO_MESSAGE_YET,
                'ledger' => self::NO_LEDGER_LINK_YET,
            ],
        ]);
    }

    /**
     * Correct a resident's record — board 38's "Edit details" (12 §2, Wave 2).
     *
     * IT EDITS WHO SOMEBODY IS, NOT WHETHER THEY ARE AUTHORISED. Verification
     * stays where the decision is made — the claims queue, or an officer
     * vouching on the add-resident screen — both of which record who decided
     * and when. A status editable from a details form would let somebody
     * verify themselves with no decision behind it.
     */
    public function editResident(Request $request, string $unit, int $resident, Residents $residents): RedirectResponse
    {
        $found = $this->resolveUnit($unit);

        $person = Resident::query()
            ->whereKey($resident)
            ->whereHas('household', static fn ($query) => $query->where('unit_id', $found->id))
            ->first();

        if ($person === null) {
            return back()->withErrors(['full_name' => 'That person is not on this household.']);
        }

        $fields = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'relationship' => ['required', 'string', 'max:40'],
            'moved_in_on' => ['nullable', 'date'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $fields['is_primary'] = $request->boolean('is_primary');

        try {
            $residents->edit($person, $fields, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['full_name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/residents/'.$found->slug()))
            ->with('success', $person->full_name.'\'s record updated. Their verification is unchanged — that is decided where a claim is reviewed, not here.');
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Put a person on the register — board 34's submit. */
    public function store(Request $request, Residents $residents): RedirectResponse
    {
        $data = $request->validate([
            'phase' => ['required', 'string', 'max:64'],
            'lot' => ['required', 'string', 'max:32'],
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'verification' => ['required', 'string', 'in:invite,manual'],

            /*
             * Absent means NO. `boolean` accepts a missing key as false, which
             * is the only correct reading of a consent checkbox nobody ticked —
             * and the default is off in the service as well, so a caller that
             * skipped this validator entirely still enrols nobody.
             */
            'biometric_consent' => ['nullable', 'boolean'],
        ]);

        try {
            $resident = $residents->add(
                phase: $data['phase'],
                lot: $data['lot'],
                fullName: $data['full_name'],
                email: $data['email'] ?? null,
                phone: $data['phone'] ?? null,
                verification: $data['verification'],
                biometricConsent: $request->boolean('biometric_consent'),
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            // Against `lot`, because the phase came from a select and the lot is
            // the field a person can actually have got wrong.
            return back()->withErrors(['lot' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/residents'))
            ->with('success', $resident->full_name.' added to '
                .($resident->household?->unit?->addressLabel() ?? 'the register').'.');
    }

    /** Approve a claim — the irreversible one. */
    public function approveClaim(Request $request, UnitClaim $claim, Residents $residents): RedirectResponse
    {
        try {
            $residents->approveClaim($claim, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['claim' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/residents/claims'))
            ->with('success', 'Claim by '.$claim->submitted_name.' approved.');
    }

    /** Refuse one, with the reason on the record. */
    public function rejectClaim(Request $request, UnitClaim $claim, Residents $residents): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $residents->rejectClaim($claim, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/residents/claims'))
            ->with('success', 'Claim by '.$claim->submitted_name.' refused.');
    }

    /** Ask the claimant for an identity document. */
    public function requestDocument(Request $request, UnitClaim $claim, Residents $residents): RedirectResponse
    {
        $data = $request->validate([
            'document' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $residents->requestDocument($claim, $data['document'] ?? 'photo ID', $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['document' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/residents/claims'))
            ->with('success', 'Identity document requested from '.$claim->submitted_name.'.');
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this viewer may be shown a resident's financial position.
     *
     * `estate.dues_ledger.view` and NOT `estate.residents.view`. They are
     * different modules because D-010 made them different: the Property Manager
     * holds Full on the register and nothing at all on the ledger, and this
     * single method is what keeps that true on four screens.
     */
    private function maySeeMoney(Request $request): bool
    {
        return $request->user()->can('estate.dues_ledger.view');
    }

    /**
     * The unit board 38 addresses by its lot — "lot-47".
     *
     * Resolved here rather than by route-model binding, because the binding
     * would have to be registered on the model and would then apply to every
     * other route that takes a unit — including the dues screens, which bind on
     * the id. One screen's URL shape is not a model's identity.
     *
     * Compared with LOWER() on both sides rather than relying on the column's
     * collation. It happens to be case-insensitive today; a schema that was
     * re-collated for some unrelated reason would otherwise turn every one of
     * these links into a 404, and a 450-row scan is not a cost worth that.
     */
    private function resolveUnit(string $slug): Unit
    {
        $reference = str_replace('-', ' ', strtolower(trim($slug)));

        $unit = Unit::query()
            ->whereRaw('LOWER(reference) = ?', [$reference])
            ->first();

        if ($unit === null) {
            abort(404);
        }

        return $unit;
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
