<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
     * Take on a new client — board screen super-admin-08.
     *
     * THIS DOES NOT PROVISION A DATABASE, and that is the important thing about
     * it. `estate:provision` creates a MySQL database and a dedicated user with
     * its own grants; that is an operator act with a command behind it, run
     * deliberately and observed while it runs. A web form that did it as a side
     * effect would create infrastructure on a button press, and a failure
     * halfway would leave a half-built estate nobody knew to look for.
     *
     * So this records the CLIENT: the tenant row, its site details, the
     * subscription it has agreed to, and the person who signed it. The estate's
     * own database is provisioned afterwards, and the screen says so.
     *
     * Nothing bills. The subscription is written in `onboarding`, and the act
     * that starts charging is completing onboarding — which has its own
     * checklist and its own audit entry.
     *
     * @param  array<string, mixed>  $data  already validated by the caller
     */
    public function start(array $data, User $actor): Tenant
    {
        return DB::connection('mysql')->transaction(function () use ($data, $actor): Tenant {
            $subdomain = $this->availableSubdomain((string) $data['name']);
            [$line, $parish] = $this->splitAddress((string) $data['address']);

            $estate = Tenant::create([
                'id' => $subdomain,
                'name' => (string) $data['name'],
                'address_line' => $line,
                'parish' => $parish,
                'status' => ClientDirectory::ONBOARDING,

                /*
                 * Phases are stored as a STRUCTURE, not a count. The form asks
                 * "how many", because at sign-up nobody has named them yet, so
                 * they are generated in order and renamed later from the estate
                 * itself. Storing the number would mean re-deriving names the
                 * first time a booking or a ballot is scoped to a phase.
                 */
                'phases' => array_map(
                    static fn (int $n): string => 'Phase '.$n,
                    range(1, max(1, (int) $data['phases'])),
                ),
            ]);

            Subscription::create([
                'tenant_id' => $subdomain,
                'plan_id' => (int) $data['plan_id'],
                'unit_count' => (int) $data['units'],
                'contracted_guards' => (int) $data['guards'],
                'term_months' => $data['term_months'] === null ? null : (int) $data['term_months'],
                'status' => ClientDirectory::ONBOARDING,
                'started_on' => now()->toDateString(),
                'renews_on' => now()->addMonth()->startOfMonth()->toDateString(),
            ]);

            /*
             * The person who signed, as a real Estate Console account in
             * `invited` state. Not active: they have not accepted, and an
             * account that can be signed into before anyone has invited them
             * is an account nobody issued.
             */
            $contact = User::updateOrCreate(
                ['email' => (string) $data['contact_email']],
                [
                    'name' => (string) $data['contact_name'],
                    'password' => Hash::make(Str::password(32)),
                    'console' => Console::Estate->value,
                    'status' => 'invited',
                ],
            );

            $role = Role::named(Role::PRESIDENT);

            EstateAssignment::updateOrCreate(
                ['user_id' => $contact->id, 'tenant_id' => $subdomain],
                ['role_id' => $role->id, 'is_active' => true],
            );

            AuditEntry::create([
                'tenant_id' => $subdomain,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'actor_role' => $actor->roles->isEmpty() ? 'No role assigned' : $actor->roles->first()->label,
                'action' => 'client.onboarding_started',
                'entity_type' => 'tenant',
                'entity_id' => $subdomain,
                'before' => null,
                'after' => [
                    'name' => $estate->name,
                    'status' => ClientDirectory::ONBOARDING,
                    'units' => (int) $data['units'],
                    'contact' => (string) $data['contact_email'],
                ],
            ]);

            return $estate;
        });
    }

    /**
     * A subdomain nobody has taken, derived from the estate's name.
     *
     * SUGGESTED, NOT FINAL. The form asks for no subdomain because the board
     * asks for none, and that is right: a subdomain becomes a hostname and a
     * database name, and it is chosen when the operator runs
     * `estate:provision` — the step that actually creates them. This gives the
     * client record an id to exist under, and the success message prints the
     * command with it in so the operator sees it before any infrastructure
     * does.
     *
     * A collision appends a digit rather than failing. Two estates called
     * "Palm Grove" is a thing that happens in a country with fourteen
     * parishes, and refusing the second one would be refusing a real client.
     */
    private function availableSubdomain(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
        $base = substr($base === '' ? 'estate' : $base, 0, 36);

        // A subdomain must start with a letter: it is a hostname label and a
        // database name suffix, and both refuse a leading digit.
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'e'.$base;
        }

        $candidate = $base;
        $suffix = 2;

        while (Tenant::query()->whereKey($candidate)->exists()) {
            $candidate = $base.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * "Mandeville, Manchester" into its street line and its parish.
     *
     * Split on the LAST comma, because a street line may contain one — "12
     * Waterloo Road, Kingston 10, St. Andrew" — and the parish is always
     * last. An address with no comma at all is all street line and no parish,
     * which is recorded as exactly that rather than guessed at.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitAddress(string $address): array
    {
        $address = trim($address);
        $at = strrpos($address, ',');

        if ($at === false) {
            return [$address, null];
        }

        return [trim(substr($address, 0, $at)), trim(substr($address, $at + 1))];
    }

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
