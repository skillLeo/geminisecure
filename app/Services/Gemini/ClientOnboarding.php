<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The estate onboarding lifecycle — board screens super-admin-08 and 09.
 *
 * An estate arrives here in `onboarding`: `estate:provision` has built its
 * database and the client record exists, but nothing is being billed and the
 * console shows it as a work in progress. Completing onboarding is the moment
 * that changes — it puts the client live and starts its subscription — and it
 * is the only thing in this module that changes what the platform bills.
 *
 * So it is a service and not four lines in a controller. The question "may this
 * client go live" has an answer that has to be the same on the screen that
 * draws the button and in the request that presses it; two copies of it would
 * be two places for the rule to drift, and the one that mattered would be the
 * one nobody looked at.
 */
class ClientOnboarding
{
    public function __construct(private readonly ClientDirectory $directory) {}

    /**
     * Why this client cannot go live, or null when it can.
     *
     * The reason is a sentence rather than a code because it is rendered
     * verbatim on the button that cannot be pressed. A reader who is told
     * "blocked" and not why has been given a locked door and no key.
     */
    public function blockedReason(Tenant $estate): ?string
    {
        if ($estate->status !== ClientDirectory::ONBOARDING) {
            return 'This client is already live; onboarding was completed for it.';
        }

        $planned = DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.tenant_id', $estate->getTenantKey())
            ->exists();

        return $planned
            ? null
            : 'This client has no prepared subscription plan, so there would be nothing to bill. Set the plan first.';
    }

    /**
     * Put an onboarding client live.
     *
     * Both writes happen together or neither does. Half of this — a live estate
     * whose subscription is still pending, or a billing subscription against an
     * estate the console still shows as onboarding — is worse than neither,
     * because both screens would then be telling the truth about different
     * halves of one client.
     *
     * `started_on` is filled in only when it is blank. An estate that was
     * contracted months ago and is being marked live today has a real start
     * date already, and overwriting it would move the anniversary the renewal
     * is counted from.
     *
     * @throws RuntimeException when the client is not in a state to go live
     */
    public function complete(Tenant $estate, User $actor): void
    {
        $blocked = $this->blockedReason($estate);

        if ($blocked !== null) {
            throw new RuntimeException($blocked);
        }

        $id = (string) $estate->getTenantKey();

        DB::connection('mysql')->transaction(function () use ($estate, $id, $actor): void {
            DB::connection('mysql')
                ->table('subscriptions')
                ->where('tenant_id', $id)
                ->update([
                    'status' => 'active',
                    'started_on' => DB::raw('COALESCE(started_on, CURDATE())'),
                    'updated_at' => Carbon::now(),
                ]);

            $estate->forceFill(['status' => 'active'])->save();

            /*
             * Recorded, because this is the act that starts charging a client
             * money. The log is append-only and central, so the answer to "who
             * put Ocean View live, and when" survives the operator's account
             * being renamed or removed.
             */
            AuditEntry::create([
                'tenant_id' => $id,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                // The role's display label, or a plain statement that there was
                // none. An audit row whose actor_role is empty reads as data
                // loss; one that says so reads as a fact.
                'actor_role' => $actor->roles->isEmpty()
                    ? 'No role assigned'
                    : $actor->roles->first()->label,
                'action' => 'client.onboarding_completed',
                'entity_type' => 'tenant',
                'entity_id' => $id,
                'before' => ['status' => ClientDirectory::ONBOARDING],
                'after' => ['status' => 'active'],
            ]);
        });
    }

    /**
     * What the detail screen needs to draw the onboarding panels.
     *
     * Delegated so the screen has one source for the checklist rather than a
     * copy of it here that could disagree with the one it renders.
     *
     * @return array<string, mixed>
     */
    public function detail(Tenant $estate): array
    {
        return $this->directory->detail((string) $estate->getTenantKey());
    }
}
