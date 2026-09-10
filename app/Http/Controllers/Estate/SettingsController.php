<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Settings;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Estate profile, users, feature toggles and the role access matrix — board
 * screens 21, 22, 23 and 24.
 *
 * READING SETTINGS IS `view`; CHANGING ONE IS NOT. The seeded estate matrix
 * gives the Settings module Full to the Community Super Admin, View to the
 * President and Vice President, and nothing at all to the Secretary, Property
 * Manager, Treasurer or Admin Assistant. So a President opens all four screens
 * and can save none of them, which is exactly what "View — read-only" means and
 * is asserted both ways in `EstateSettingsTest`.
 *
 * THERE IS NO ACTION HERE THAT CHANGES A PERMISSION, AND THAT IS DELIBERATE.
 * Board 24 draws the matrix and draws no control that edits it. Changing what a
 * role may do is an `approve`-level act (D-013), and no estate role holds
 * `estate.settings.approve` — not even the Community Super Admin, whose Full
 * cell carries no Approver tag (D-008). A settings screen is precisely where a
 * privilege-escalation route would appear by accident, so it is refused three
 * separate ways: no route, no service method, and a `can_edit` flag on the
 * payload that reads false for every one of the seven roles. See
 * `App\Services\Estate\Settings` for the full argument.
 *
 * The route file carries the gates. A controller that re-checked them would be
 * a second place to keep in step with the matrix.
 */
class SettingsController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it is drawn on,
     * and each needs a form, an entity or a delivery route this phase has not
     * built.
     */
    private const NO_INVITE_YET = 'Not built yet — an invitation issues a credential to somebody who is not yet a '.
        'user, so it needs the mailbox, the role and the expiry captured together, and an email that actually '.
        'leaves the building. A half-built invite is an account nobody can finish creating.';

    private const NO_MANAGE_USER_YET = 'Not built yet — changing somebody\'s role changes what they may do to this '.
        'estate\'s money and records, which needs its own screen with the matrix shown beside the choice rather '.
        'than a dropdown on a list.';

    private const NO_LOGO_UPLOAD_YET = 'Not built yet — a logo is printed on resident notices and receipts, so it '.
        'needs a size and format rule, a stored original, and somewhere for the old one to go. A file input '.
        'without those is a broken image on 450 households\' paperwork.';

    /**
     * Why board 40's "View" beside an invoice does nothing yet.
     *
     * An itemised invoice is a central Gemini Console record — the same one
     * `App\Http\Controllers\Gemini\BillingController::invoice()` already
     * serves — and this release does not build a second copy of that screen
     * behind the estate's own hostname. Reaching it from here needs a route
     * that can prove the viewer's estate owns the invoice being asked for,
     * which is a real access-control decision and not a link to reuse.
     */
    private const NO_INVOICE_VIEW_YET = 'Not built yet — an invoice\'s own line-by-line detail is a central Gemini '.
        'Console record, and reaching it from the estate\'s own hostname needs a route that can prove this estate '.
        'owns the invoice being asked for. The summary above is exact; the itemised view is not built yet.';

    /** Estate profile — board community-admin-21. */
    public function profile(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Profile', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('profile', (string) tenant()->getTenantKey()),
            ...$settings->profileBoard(),
            'canEdit' => $request->user()->can('estate.settings.update'),
            'blockedReason' => 'Saving the estate profile needs Settings update access. You are able to read this screen.',
            'reasons' => [
                'logo' => self::NO_LOGO_UPLOAD_YET,
            ],
        ]);
    }

    /** Users & roles — board community-admin-22. */
    public function users(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Users', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('users', (string) tenant()->getTenantKey()),
            ...$settings->usersBoard((string) tenant()->getTenantKey()),
            'canInvite' => $request->user()->can('estate.settings.create'),
            'blockedReason' => 'Inviting a user needs Settings create access. You are able to read this screen.',
            'reasons' => [
                'invite' => self::NO_INVITE_YET,
                'manage' => self::NO_MANAGE_USER_YET,
            ],
        ]);
    }

    /** Feature toggles — board community-admin-23. */
    public function features(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Features', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('features', (string) tenant()->getTenantKey()),
            ...$settings->featuresBoard((string) tenant()->getTenantKey()),

            /*
             * `configure` and not `update`. Switching a module off changes what
             * several hundred households can do tomorrow, which is the verb
             * `AccessLevel::Full` grants and `Entry` deliberately does not —
             * "data entry, no approval" is not the same permission as changing
             * the shape of the product an estate is running.
             */
            'canToggle' => $request->user()->can('estate.settings.configure'),
            'blockedReason' => 'Changing a feature needs Settings configure access. You are able to read this screen.',
        ]);
    }

    /** Role access matrix — board community-admin-24. */
    public function roles(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Roles', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('roles', (string) tenant()->getTenantKey()),
            ...$settings->matrixBoard($request->user()),
            'canInvite' => $request->user()->can('estate.settings.create'),
            'reasons' => [
                'invite' => self::NO_INVITE_YET,
            ],
        ]);
    }

    /** Notification defaults — board community-admin-30. */
    public function notifications(Request $request, Settings $settings): Response
    {
        return inertia('Estate/Settings/Notifications', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('notifications', (string) tenant()->getTenantKey()),
            ...$settings->notificationsBoard(),
            'canEdit' => $request->user()->can('estate.settings.update'),
            'blockedReason' => 'Changing a notification default needs Settings update access. You are able to read this screen.',
        ]);
    }

    /** Data & privacy — board community-admin-33. Every field is read-only; see Settings::privacyBoard(). */
    public function privacy(Settings $settings): Response
    {
        return inertia('Estate/Settings/Privacy', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('privacy', (string) tenant()->getTenantKey()),
            ...$settings->privacyBoard(),
        ]);
    }

    /**
     * Billing & subscription — board community-admin-40.
     *
     * Entirely a read of the CENTRAL subscription record. See
     * `Settings::billingBoard()` for the whole argument; nothing here decides
     * anything the service has not already decided.
     */
    public function billing(Settings $settings): Response
    {
        return inertia('Estate/Settings/Billing', [
            'estate' => ['name' => (string) tenant()->name],
            'sections' => $settings->sections('billing', (string) tenant()->getTenantKey()),
            ...$settings->billingBoard((string) tenant()->getTenantKey()),
            'reasons' => [
                'view_invoice' => self::NO_INVOICE_VIEW_YET,
            ],
        ]);
    }

    /**
     * Save all eighteen of board 30's switches in one submit.
     *
     * ONE SUBMIT, ONE AUDIT ENTRY, exactly as the board draws it — a single
     * topbar "Save changes" rather than Features' per-row arm-and-confirm.
     * Nothing here disables a module or changes what a household may do; it
     * decides who is emailed, texted or pushed about something that has already
     * happened, which is a contact preference in the same register as the
     * estate's enquiries mailbox on board 21.
     *
     * THE POSTED ARRAY IS NOT THE ALLOWLIST. `Settings::saveNotifications()`
     * walks its own `NOTIFICATION_EVENTS` and reads each key out of the input,
     * so an extra key in the request body reaches nothing — the same shape as
     * the fixed three-key allowlist on `saveProfile()`.
     */
    public function saveNotifications(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'defaults' => ['present', 'array'],
        ]);

        $settings->saveNotifications($data['defaults'], $request->user());

        return back()->with('flash', 'Notification defaults saved.');
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /**
     * Save the estate's own contact details.
     *
     * FOUR OF BOARD 21'S SEVEN INPUTS ARE NOT VALIDATED HERE, because they are
     * not accepted at all. The estate name, address, unit count and phase count
     * are the central client record and this estate's own units; the payload
     * marks each of them `editable: false` with the reason, and a request that
     * posted them anyway would have them ignored rather than silently applied.
     */
    public function saveProfile(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enquiries_email' => ['nullable', 'email', 'max:160'],
            'enquiries_phone' => ['nullable', 'string', 'max:40'],
            'security_provider' => ['required', 'string', 'max:120'],
        ]);

        try {
            $settings->updateProfile($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['security_provider' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/settings/profile'))
            ->with('success', 'Estate profile saved.');
    }

    /**
     * Switch a feature, or choose a payroll routing.
     *
     * ONE ACTION FOR BOTH CONTROLS, because board 23 draws both on one panel
     * and a page posting to two different endpoints depending on which row was
     * clicked would have to know which rows are segmented. The payload already
     * says — `control` is `switch`, `segmented` or `scale` — so the request
     * carries `enabled` or `option` and this dispatches on which arrived.
     *
     * THE REASON IS REQUIRED IN BOTH SHAPES. Board 23 states it: every toggle
     * change writes an audit record with actor, timestamp AND reason, and a
     * reason nobody typed is not one.
     */
    public function updateFeature(Request $request, string $feature, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:300'],
            'enabled' => ['required_without:option', 'boolean'],
            'option' => ['required_without:enabled', 'string', 'max:24'],
        ]);

        try {
            if (array_key_exists('option', $data)) {
                $settings->setRouting(
                    key: $feature,
                    option: (string) $data['option'],
                    reason: (string) $data['reason'],
                    by: $request->user(),
                );
            } else {
                $settings->setFeature(
                    key: $feature,
                    enabled: $request->boolean('enabled'),
                    reason: (string) $data['reason'],
                    by: $request->user(),
                    tenantKey: (string) tenant()->getTenantKey(),
                );
            }
        } catch (DomainException $refused) {
            /*
             * Against `enabled`, because that is the control the person moved —
             * and where the refusal is about the plan or the ruling rather than
             * the switch, the message says so rather than leaving them toggling
             * something that was never the problem.
             */
            return back()->withErrors(['enabled' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/features'))
            ->with('success', 'Feature settings saved.');
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
