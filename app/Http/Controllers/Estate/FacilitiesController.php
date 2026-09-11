<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Vendor;
use App\Services\Estate\Amenities;
use App\Services\Estate\Maintenance;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Maintenance and amenities — board screens community-admin-17, 18, 19 and 20,
 * and the booking detail that no board draws (D-086).
 *
 * NOT ONE FIGURE ON THESE FOUR SCREENS IS A RESIDENT'S FINANCIAL POSITION, and
 * that is a platform invariant rather than a design choice. The persona on every
 * one of these boards is the Property Manager, who holds `facilities` in full
 * and holds `-` on `dues_ledger`, `payments` and `accounting_posting` — D-010
 * split those modules precisely so the person who commissions the work could see
 * what a job costs without ever reaching what a household owes. So no payload
 * below carries a balance, a bucket, an arrears age or a days-overdue figure;
 * the arrears rule on board 19 reaches this controller as a boolean and as
 * nothing else, and `EstateFacilitiesMaintenanceTest` and
 * `EstateFacilitiesAmenitiesTest` walk these payloads to prove it.
 *
 * OVERDUE IS NEVER READ FROM A COLUMN. Board 17's red chip, its Overdue tile and
 * every Overdue badge in its table come from the same arithmetic — the time
 * reported plus the service target for the priority — computed at draw time.
 * A ticket that sat unassigned for a week is overdue, and there is no stored
 * flag that could have been left saying otherwise.
 *
 * CHARGING A BOOKING FEE NEEDS TWO GATES AND THE SECOND IS NOT `facilities`.
 * Recording the fee posts a charge against a unit — Dr 1200, Cr 4100 — which
 * adds to what a household owes, and that is `estate.dues_ledger.create` wherever
 * it happens. The route carries both; the Property Manager holds the first and
 * not the second, so board 19's "record a fee" is inert for them with the reason
 * on it. The board names it among their actions and the invariant outranks the
 * board.
 *
 * THE BOOKING DETAIL IS THE ONE SCREEN HERE NO BOARD DRAWS (D-086). The client
 * ruled that a service moving money with no route is a worse gap than the
 * caution it replaced, and put the deposit door on it: Facilities `update`
 * records a deposit received or refunded, Facilities `approve` forfeits one —
 * keeping a resident's money is the higher verb and carries a reason. No
 * accounting gate sits beside them, deliberately: the ruling made this the
 * Property Manager's screen, and D-010 locks that role out of Accounting.
 */
class FacilitiesController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it sits on, and
     * each needs a form, a thread or a table this phase has not built.
     */
    private const NO_WORK_ORDER_YET = 'Not built yet — a work order raised by the office rather than reported by a resident still starts an SLA clock, so it needs the location, the category and the priority chosen deliberately. That is a form, not a button.';

    private const NO_MESSAGE_RESIDENT_YET = 'Not built yet — messaging the reporter opens a thread that reaches their phone, and a maintenance ticket is not the place to invent one. It belongs with the notices module.';

    private const NO_ADD_AMENITY_YET = 'Not built yet — adding an amenity sets a fee and a deposit that every future booking copies, so it needs capacity, hours and both amounts captured together rather than a blank card.';

    private const NO_EDIT_AMENITY_YET = 'Not built yet — editing the rate card changes what future bookings will cost and must leave every confirmed booking exactly as it was. That rule needs its own screen to state it on.';

    private const NO_VENDORS_TAB_YET = 'The supplier register is the Accounting module\'s screen and needs Accounting view access, which this role does not hold. It is the same register these tickets are assigned from.';

    /** The maintenance queue — board community-admin-17. */
    public function maintenance(Request $request, Maintenance $maintenance): Response
    {
        return inertia('Estate/Facilities/Maintenance', [
            'estate' => ['name' => (string) tenant()->name],
            ...$maintenance->queueBoard($request->string('filter')->toString()),

            /*
             * The ladder the queue can be triaged against. Board 17's own
             * `blockedReason` names triage, and triage on a list screen is the
             * priority — the row's one act. Sent as {key: label} so the page
             * never spells the enum out itself and a fourth rung would appear
             * on both screens at once.
             */
            'priorities' => MaintenanceTicket::PRIORITY_LABELS,
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canCreate' => $request->user()->can('estate.facilities.create'),
            // The Vendors tab is the supplier register, which is Accounting's
            // screen behind Accounting's gate. A link for whoever holds it; the
            // inert twin and `reasons.vendors` for whoever does not (D-010).
            'canViewVendors' => $request->user()->can('estate.accounting_posting.view'),
            'blockedReason' => 'Triaging a ticket changes what a vendor is asked to do and needs Facilities update access. You are able to read this screen.',
            'reasons' => [
                'workOrder' => self::NO_WORK_ORDER_YET,
                'vendors' => self::NO_VENDORS_TAB_YET,
            ],
        ]);
    }

    /** One ticket end to end — board community-admin-18. */
    public function ticket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): Response
    {
        return inertia('Estate/Facilities/Ticket', [
            'estate' => ['name' => (string) tenant()->name],
            ...$maintenance->ticketBoard($ticket),

            /*
             * The register this ticket can be assigned from. Names only — a
             * vendor's TRN and what it is owed are the Accounting module's, and
             * this screen has no business carrying either.
             */
            'vendors' => Vendor::query()
                ->where('status', Vendor::ACTIVE)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Vendor $vendor): array => ['id' => $vendor->id, 'name' => $vendor->name])
                ->all(),
            'priorities' => MaintenanceTicket::PRIORITY_LABELS,
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'blockedReason' => 'Completing or reassigning a ticket needs Facilities update access. You are able to read this screen.',
            'reasons' => [
                'message' => self::NO_MESSAGE_RESIDENT_YET,
            ],
        ]);
    }

    /** The booking diary — board community-admin-19. */
    public function bookings(Request $request, Amenities $amenities): Response
    {
        return inertia('Estate/Facilities/Bookings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$amenities->bookingsBoard($request->string('amenity')->toString()),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canCreate' => $request->user()->can('estate.facilities.create'),
            'canViewVendors' => $request->user()->can('estate.accounting_posting.view'),

            /*
             * BOTH GATES, because the route carries both.
             *
             * The fee is a charge on a unit, so it needs `dues_ledger.create` —
             * and it is still a facilities act, so it needs `facilities.update`
             * beside it. Testing only the ledger half would draw the control
             * live for a Treasurer, who holds Dues & ledger in full and
             * Facilities as VIEW, and the POST behind that live button would be
             * refused by the route. A screen that offers an act its own route
             * will not accept is worse than one that explains itself: the
             * person clicking it has no way to know which of the two gates they
             * are missing, and the estate's answer arrives as a 403.
             */
            'canCharge' => $request->user()->can('estate.facilities.update')
                && $request->user()->can('estate.dues_ledger.create'),
            'chargeReason' => 'Recording a booking fee posts a charge against the unit, so it needs Dues & ledger create access as well as Facilities update. Whoever commissions the work in this estate holds the second and not the first, by platform rule rather than by estate preference.',
            'reasons' => [
                'vendors' => self::NO_VENDORS_TAB_YET,
            ],
        ]);
    }

    /** The rate card — board community-admin-20. */
    public function amenitySettings(Request $request, Amenities $amenities): Response
    {
        return inertia('Estate/Facilities/AmenitySettings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$amenities->settingsBoard(),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'reasons' => [
                'add' => self::NO_ADD_AMENITY_YET,
                'edit' => self::NO_EDIT_AMENITY_YET,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Put a vendor on a ticket, or a different one. */
    public function assignVendor(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            'technician_name' => ['nullable', 'string', 'max:160'],
            'technician_phone' => ['nullable', 'string', 'max:40'],
            'eta_starts_at' => ['nullable', 'date'],
            'eta_ends_at' => ['nullable', 'date', 'after:eta_starts_at'],
        ]);

        $vendor = Vendor::findOrFail($data['vendor_id']);

        try {
            $maintenance->assign(
                ticket: $ticket,
                vendor: $vendor,
                technicianName: $data['technician_name'] ?? null,
                technicianPhone: $data['technician_phone'] ?? null,
                etaStartsAt: $data['eta_starts_at'] ?? null,
                etaEndsAt: $data['eta_ends_at'] ?? null,
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['vendor_id' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Assigned to '.$vendor->name.'.');
    }

    /**
     * Raise or lower the priority.
     *
     * The deadline moves with it and the clock does not reset — an escalated
     * ticket is measured against the new target from the moment it was reported.
     */
    public function changePriority(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'priority' => ['required', 'string', 'in:high,medium,low'],
        ]);

        try {
            $maintenance->changePriority($ticket, $data['priority'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['priority' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Priority set to '.MaintenanceTicket::PRIORITY_LABELS[$data['priority']].'.');
    }

    /** Mark it completed — board 18's primary action. */
    public function resolveTicket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $maintenance->resolve($ticket, $data['resolution'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['resolution' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance'))
            ->with('success', 'Ticket #'.$ticket->number.' completed.');
    }

    /** It came back. The original closure stays in the log. */
    public function reopenTicket(Request $request, MaintenanceTicket $ticket, Maintenance $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ]);

        try {
            $maintenance->reopen($ticket, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/maintenance/'.$ticket->number))
            ->with('success', 'Ticket #'.$ticket->number.' reopened.');
    }

    /** Approve a pending booking. */
    public function approveBooking(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        try {
            $amenities->approve($booking, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['booking' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', 'Booking '.$booking->reference.' confirmed.');
    }

    /** Decline it, with the reason the household will be given. */
    public function declineBooking(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $amenities->decline($booking, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', 'Booking '.$booking->reference.' declined.');
    }

    /** Take a period out of an amenity's diary. */
    public function blockSlot(Request $request, Amenity $amenity, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $amenities->blockSlot(
                amenity: $amenity,
                startsAt: $data['starts_at'],
                endsAt: $data['ends_at'],
                reason: $data['reason'] ?? null,
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', $amenity->name.' blocked for that period.');
    }

    /**
     * Charge the booking fee to the unit.
     *
     * A REAL POSTING, and the route carries `estate.dues_ledger.create` beside
     * the facilities gate for it. See the class docblock.
     */
    public function recordBookingFee(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'due_on' => ['nullable', 'date'],
        ]);

        try {
            $amenities->recordFee($booking, $request->user(), $data['due_on'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['fee' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings'))
            ->with('success', 'Booking fee charged to '.$booking->unit->reference.'.');
    }

    /**
     * One booking and the deposit behind it — the screen no board draws (D-086).
     *
     * Read on `view`, like every screen in this module. The two gates on the
     * deposit acts are sent as two booleans rather than one, because the screen
     * has to say WHICH one a viewer lacks: an Admin Assistant may record a
     * deposit received and may not keep one, and a single `canAct` would draw
     * them either three live buttons or none.
     */
    public function booking(Request $request, AmenityBooking $booking, Amenities $amenities): Response
    {
        return inertia('Estate/Facilities/Booking', [
            'estate' => ['name' => (string) tenant()->name],
            ...$amenities->bookingBoard($booking),
            'canUpdate' => $request->user()->can('estate.facilities.update'),
            'canApprove' => $request->user()->can('estate.facilities.approve'),
            'updateReason' => 'Recording a deposit received or refunded moves the estate\'s cash, so it needs Facilities update access. You are able to read this booking.',
            'approveReason' => 'Forfeiting keeps a resident\'s money as the estate\'s income, so it needs Facilities approval as well as update — the higher verb — and a reason. Your role holds Facilities without the Approver tag.',
        ]);
    }

    /**
     * The deposit has arrived — Dr 1000 Bank, Cr 2200 Deposits Held.
     *
     * `facilities.update` on the route, as ruled. The date is optional and means
     * today when absent; a deposit paid last Friday and recorded on Monday is
     * posted on the Friday, because that is when the bank had it.
     */
    public function holdDeposit(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            $amenities->holdDeposit($booking, $data['on'] ?? null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['deposit' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings/'.$booking->id))
            ->with('success', sprintf(
                'Deposit of %s on %s recorded as received and held.',
                AmenityBooking::amount($booking->deposit_minor),
                $booking->reference,
            ));
    }

    /** It has gone back — Dr 2200, Cr 1000. `facilities.update`, as ruled. */
    public function refundDeposit(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            $amenities->refundDeposit($booking, $data['on'] ?? null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['deposit' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings/'.$booking->id))
            ->with('success', sprintf(
                'Deposit of %s on %s refunded.',
                AmenityBooking::amount($booking->deposit_minor),
                $booking->reference,
            ));
    }

    /**
     * The estate is keeping it — Dr 2200, Cr 4100.
     *
     * `facilities.approve` on the route: forfeiting keeps a resident's money and
     * the ruling gives it "the higher verb plus its required reason". The reason
     * is required here AND refused blank by the service, so a caller that
     * reaches the service some other way cannot keep money without one either.
     */
    public function forfeitDeposit(Request $request, AmenityBooking $booking, Amenities $amenities): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ]);

        try {
            $amenities->forfeitDeposit($booking, $data['reason'], null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/facilities/amenities/bookings/'.$booking->id))
            ->with('success', sprintf(
                'Deposit of %s on %s forfeited. The reason is recorded on the booking and in the entry.',
                AmenityBooking::amount($booking->deposit_minor),
                $booking->reference,
            ));
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
