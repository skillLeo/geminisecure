<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who stands this client's gates - board screen super-admin-10.
 *
 * The operational contract behind the commercial one. Board 06 sets how many
 * guards a client PAYS for; this screen decides which officers those are and
 * which post each one holds.
 *
 * THE ROSTER IS SAVED WHOLE, not one row at a time, and that is the board's own
 * design: it draws a Save button in the topbar and the spec lists "save the
 * roster" as an action in its own right beside assign, unassign and reassign.
 * The reason is worth stating, because committing each row on click would have
 * been less code. Moving an officer off the Service Gate and another onto it is
 * ONE decision about one estate's cover. Committed row by row, it passes through
 * a state where the gate is unmanned in the record - and the coverage board next
 * door reads that record. A roster saved whole is either the old cover or the
 * new one, never a gap that existed only because someone clicked in the order
 * they did.
 *
 * ONE RULE IS ABSOLUTE, and it is the spec's: a guard whose PSRA licence has
 * lapsed cannot be assigned. Not warned about - blocked, here in the service, so
 * the answer is the same whether it is the picker asking or a hand-made POST.
 * An officer ALREADY on post with a lapsed licence is a different question and
 * is deliberately left alone: D-034 keeps them visible to the client whose gate
 * they are standing, and quietly clearing the posting here would tidy the
 * client's screen while the problem stood at their gate. They can be removed.
 * They cannot be re-added.
 *
 * Everything is read from and written to gs_platform. Guards, posts and estates
 * are all central; no estate database is opened.
 */
class ClientGuardAssignment
{
    /**
     * Guard statuses that mean "not standing a post today".
     *
     * They stay ON the roster - a guard on leave is still contracted to this
     * estate and still shows on it - but they are not counted as filling their
     * post, because the fill figure is what an operator reads to decide whether
     * cover has to be found for tonight.
     *
     * @var list<string>
     */
    private const NOT_ON_DUTY = ['on_leave', 'suspended', 'licence_expired'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Everything the roster screen renders.
     *
     * @return array<string, mixed>
     */
    public function roster(Tenant $estate): array
    {
        $id = (string) $estate->getTenantKey();

        $subscription = DB::connection('mysql')
            ->table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.tenant_id', $id)
            ->select('subscriptions.contracted_guards', 'plans.name as plan_name')
            ->first();

        $assigned = $this->assigned($id);
        $contracted = $subscription?->contracted_guards === null
            ? null
            : (int) $subscription->contracted_guards;

        $onDuty = $assigned->reject(
            fn (Guard $guard): bool => in_array($guard->status, self::NOT_ON_DUTY, true)
        )->count();

        return [
            'id' => $id,
            'name' => (string) $estate->name,
            // The board's own heading, em dash included.
            'title' => 'Guard assignment — '.$estate->name,

            /*
             * "Premium tier - Contracted for 4 guards", the board's own line.
             *
             * Each half falls away when it is not known rather than printing a
             * placeholder, so a client with no plan reads as incomplete instead
             * of as contracted for nothing.
             */
            'subtitle' => implode(' · ', array_filter([
                $subscription?->plan_name === null ? null : $subscription->plan_name.' tier',
                $this->contractLine($contracted),
            ])),

            'fill' => $this->fill($contracted, $onDuty, $assigned->count()),
            'assigned' => $assigned->map(fn (Guard $guard): array => $this->row($guard))->values()->all(),
            'posts' => $this->posts($id),
            'pool' => $this->pool($id),
            'poolBlockedReason' => $this->poolBlockedReason($id),
        ];
    }

    /**
     * Commit a roster.
     *
     * @param  list<array{guard_id: int, post_id: int|null}>  $roster
     *
     * @throws RuntimeException when a submitted line breaks a rule the picker enforces
     */
    public function save(Tenant $estate, array $roster): void
    {
        $id = (string) $estate->getTenantKey();

        $before = $this->assigned($id);
        $postNames = $this->postNames($id);

        /** @var array<int, int|null> $wanted guard id => post id */
        $wanted = [];

        foreach ($roster as $line) {
            $wanted[$line['guard_id']] = $line['post_id'];
        }

        $guards = Guard::query()->whereIn('id', array_keys($wanted))->get()->keyBy('id');

        foreach ($wanted as $guardId => $postId) {
            $guard = $guards->get($guardId);

            if ($guard === null) {
                throw new RuntimeException('One of the officers on this roster is no longer on the workforce. Nothing has been saved; reload the screen to see who is.');
            }

            if ($postId !== null && ! isset($postNames[$postId])) {
                throw new RuntimeException("A post on this roster does not belong to {$estate->name}. Nothing has been saved.");
            }

            /*
             * The licence block, and the exception to it.
             *
             * Only a guard being NEWLY put on this estate is checked. One
             * already here with a lapsed licence stays visible to the client
             * (D-034) and may be removed, but may not be moved from post to
             * post as though the lapse were a detail.
             */
            $wasHere = $before->contains(fn (Guard $existing): bool => $existing->getKey() === $guard->getKey());

            if (! $wasHere && $guard->licenceState() !== 'valid') {
                throw new RuntimeException("{$guard->full_name}'s PSRA licence is not current, so they cannot be assigned to a post. Record the renewed licence on their record first.");
            }

            if (! $wasHere && $guard->tenant_id !== null && $guard->tenant_id !== $id) {
                throw new RuntimeException("{$guard->full_name} is already posted at another client. Take them off that estate's roster before assigning them here.");
            }
        }

        DB::connection('mysql')->transaction(function () use ($before, $wanted, $id, $postNames): void {
            foreach ($before as $guard) {
                if (! array_key_exists($guard->getKey(), $wanted)) {
                    /*
                     * Taken off this estate, not deleted and not suspended.
                     * They remain an employee of Gemini Security with no
                     * posting, which is what the workforce pool is.
                     */
                    $guard->forceFill(['tenant_id' => null, 'post_id' => null])->save();
                }
            }

            foreach ($wanted as $guardId => $postId) {
                Guard::query()->whereKey($guardId)->update(['tenant_id' => $id, 'post_id' => $postId]);
            }

            $this->audit->record(
                action: 'tenant.guard_roster_saved',
                entityType: 'Tenant',
                entityId: $id,
                before: ['roster' => $this->summarise($before, $postNames)],
                after: ['roster' => $this->summarise($this->assigned($id), $postNames)],
                tenantId: $id,
            );
        });
    }

    /* ------------------------------------------------------------------ */
    /* reading */
    /* ------------------------------------------------------------------ */

    /**
     * Everyone posted to this estate, whatever state they are in.
     *
     * Deliberately NOT the same set the client detail screen calls "deployed".
     * That one drops guards on leave, because it answers "who is standing this
     * estate's gates". This one answers "who is on this estate's roster", and a
     * guard on leave who vanished from it would be silently unassigned by the
     * next save.
     *
     * @return Collection<int, Guard>
     */
    private function assigned(string $tenantId): Collection
    {
        return Guard::query()
            ->with('post')
            ->where('tenant_id', $tenantId)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * One roster row, in the board's shape.
     *
     * The row's second line is sent in TWO pieces, and that is deliberate. The
     * board draws it whole — "Service Gate · PSRA-004498 — licence expired" —
     * but the screen lets an operator restage which post an officer holds
     * before saving, and a line composed on the server would go on naming the
     * old gate while the picker beside it named the new one. The post is
     * therefore whatever the staged `postId` currently resolves to, and only
     * the part that cannot change — the licence number, and any note about the
     * officer's state — is fixed here.
     *
     * @return array<string, mixed>
     */
    private function row(Guard $guard): array
    {
        return [
            'id' => $guard->getKey(),
            'initials' => $this->initials($guard->full_name),
            'name' => $guard->full_name,
            'postId' => $guard->post_id,
            'suffix' => $this->suffix($guard),
            'href' => '/guards/'.$guard->getKey(),
        ];
    }

    /**
     * Everything after the post name: " · PSRA-004498 — licence expired".
     *
     * The note is only ever present when there is something wrong, so a row
     * without one reads as an officer on post and needs no further reading.
     */
    private function suffix(Guard $guard): string
    {
        $note = match (true) {
            $guard->licenceState() === 'expired' => 'licence expired',
            $guard->licenceState() === 'unknown' => 'no licence expiry on file',
            $guard->status === 'on_leave' => 'on leave',
            $guard->status === 'suspended' => 'suspended',
            default => null,
        };

        return ' · '.$guard->psra_number.($note === null ? '' : ' — '.$note);
    }

    /**
     * The pill beside the estate name: "4 of 4 filled".
     *
     * Green when cover is met and amber when it is short, which are the two
     * treatments the boards give this slot - board 10 draws the met case in
     * green, board 05 draws the same pill in the amber tokens. No third colour
     * is invented for the over-filled case: a client with more officers than
     * contracted is not a problem to alarm anyone about, it is a billing
     * conversation, and board 06 is where it is had.
     *
     * @return array{label: string, short: bool}
     */
    private function fill(?int $contracted, int $onDuty, int $assigned): array
    {
        if ($contracted === null) {
            /*
             * No guard cover contracted. The estate may still have officers on
             * it - a trial, or cover being run before the contract catches up -
             * so the count is stated rather than suppressed, and named as
             * uncontracted so nobody reads it as cover that is being paid for.
             */
            return [
                'label' => $assigned === 0
                    ? 'No guard cover contracted'
                    : $assigned.' assigned, none contracted',
                'short' => $assigned > 0,
            ];
        }

        return [
            'label' => $onDuty.' of '.$contracted.' filled',
            'short' => $onDuty < $contracted,
        ];
    }

    /** "Contracted for 4 guards", or nothing at all when no cover is contracted. */
    private function contractLine(?int $contracted): ?string
    {
        return match (true) {
            $contracted === null => null,
            $contracted === 1 => 'Contracted for 1 guard',
            default => 'Contracted for '.$contracted.' guards',
        };
    }

    /**
     * This estate's posts, for the reassign picker.
     *
     * Only this estate's, which is the whole safety of the control: a post
     * belongs to one estate, and a picker offering another client's gates would
     * let a save move an officer across a tenant boundary by accident.
     *
     * @return list<array{id: int|null, name: string}>
     */
    private function posts(string $tenantId): array
    {
        $posts = Post::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Post $post): array => ['id' => $post->getKey(), 'name' => $post->name])
            ->values()
            ->all();

        // An officer contracted to the estate but not yet given a gate is a
        // real state, so it has to be selectable rather than only arrivable at.
        return [['id' => null, 'name' => 'Unposted'], ...$posts];
    }

    /**
     * Guards who may be added to this estate.
     *
     * Unposted, and licensed. The lapsed are absent rather than present and
     * refused, because the spec is explicit that the picker blocks rather than
     * warns - an operator who can see a name in a list will eventually try it.
     *
     * Shaped exactly like an assigned row, so a guard picked out of the pool
     * renders as a roster line without a second trip to the server. They arrive
     * unposted, because being contracted to an estate and being given one of
     * its gates are two decisions and the second one is the picker next to them.
     *
     * @return list<array{id: int, initials: string, name: string, postId: null, suffix: string, href: string, label: string}>
     */
    private function pool(string $tenantId): array
    {
        return Guard::query()
            ->whereNull('tenant_id')
            ->whereNotIn('status', ['suspended', 'licence_expired'])
            ->orderBy('full_name')
            ->get()
            ->reject(fn (Guard $guard): bool => $guard->licenceState() !== 'valid')
            ->map(fn (Guard $guard): array => [
                'id' => $guard->getKey(),
                'initials' => $this->initials($guard->full_name),
                'name' => $guard->full_name,
                'postId' => null,
                'suffix' => $this->suffix($guard),
                'href' => '/guards/'.$guard->getKey(),
                // What the picker itself reads. The employee number is here and
                // not on the roster row because it is how one Marcus is told
                // from another in a list of names, and is noise once posted.
                'label' => $guard->full_name.' · '.$guard->employee_number,
            ])
            ->values()
            ->all();
    }

    /**
     * Why nobody can be added, or null when someone can.
     *
     * An empty picker with no explanation reads as a broken control. This says
     * which of the two reasons it is, because they need different actions from
     * whoever is reading: hire, or chase a renewal.
     */
    private function poolBlockedReason(string $tenantId): ?string
    {
        if ($this->pool($tenantId) !== []) {
            return null;
        }

        $unpostedButUnlicensed = Guard::query()->whereNull('tenant_id')->exists();

        return $unpostedButUnlicensed
            ? 'Every unposted officer has a lapsed or unrecorded PSRA licence, and an unlicensed guard cannot be put on a gate. Clear the compliance register first.'
            : 'Every officer on the workforce is already posted to a client. Take one off another estate, or add a guard to the workforce.';
    }

    /**
     * A roster as the audit log records it: names and posts, not ids.
     *
     * An audit row that reads "guard 3 moved to post 7" is a row a reviewer has
     * to go and decode from tables that may since have changed.
     *
     * @param  Collection<int, Guard>  $guards
     * @param  array<int, string>  $postNames
     * @return list<string>
     */
    private function summarise(Collection $guards, array $postNames): array
    {
        return $guards
            ->map(fn (Guard $guard): string => $guard->full_name.' — '.(
                $guard->post_id === null ? 'Unposted' : ($postNames[$guard->post_id] ?? 'Unposted')
            ))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function postNames(string $tenantId): array
    {
        /** @var array<int, string> $names */
        $names = Post::query()
            ->where('tenant_id', $tenantId)
            ->pluck('name', 'id')
            ->all();

        return $names;
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
