<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Console;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demonstration data for both estates, plus the accounts the Phase 1 gate
 * authenticates as.
 *
 * Deliberately seeds TWO estates with overlapping record ids. The gate asks
 * for Ocean View's resident #1 while authenticated as a Phoenix Park
 * committee member; if both estates have a resident #1, a leak returns a
 * plausible record rather than a 404, which is the failure mode worth
 * catching.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Where these clients are, as the approved boards describe them.
     *
     * An estate is created by `estate:provision <subdomain> <name>`, which
     * knows nothing about a site beyond its name. The boards do: "Waterloo
     * Road, St. Andrew · 5 phases · Client since Mar 2024". Those are facts
     * about a physical place a security company guards, so they are seeded
     * here and stored as real columns rather than left implied by a screen.
     *
     * The name matters for a second reason: the client detail hero sizes to
     * its content and everything beside it follows, so "Phoenix Park" against
     * the board's "Phoenix Park Village 1" moved the whole action column.
     *
     * `status` is here for the same reason the address is: it is a fact the
     * boards state about these two clients, not an accident of when the
     * database was built. Four boards draw Ocean View mid-onboarding — the
     * directory badges it "Onboarding" and shows no MRR, its detail draws an
     * onboarding checklist, and both the plan and the new-client boards refer
     * to it in those words — while Phoenix Park is drawn live throughout.
     * Provisioning leaves every estate in whichever state it was created in,
     * so without this the demo platform has no onboarding client at all and
     * that half of the client detail screen can never be reached.
     *
     * @var array<string, array{name: string, address_line: string, parish: string, gate_count: int, phases: list<string>, status: string}>
     */
    private const BOARD_NAMES = [
        'phoenixpark' => [
            'name' => 'Phoenix Park Village 1',
            'address_line' => 'Waterloo Road',
            'parish' => 'St. Andrew',
            'gate_count' => 3,
            'phases' => ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'],
            'status' => 'active',
        ],
        // Portmore, St. Catherine — not Norman Manley Boulevard, St. James,
        // which is a Montego Bay address and belongs to no board.
        'oceanview' => [
            'name' => 'Ocean View Gardens',
            'address_line' => 'Portmore',
            'parish' => 'St. Catherine',
            'gate_count' => 2,
            'phases' => ['Phase 1', 'Phase 2'],
            'status' => 'onboarding',
        ],
    ];

    public function run(): void
    {
        $this->seedGeminiStaff();

        foreach (self::BOARD_NAMES as $subdomain => $site) {
            Tenant::find($subdomain)?->forceFill($site)->save();
        }

        foreach (Tenant::estates() as $tenant) {
            $this->seedEstateCommittee($tenant);

            $tenant->run(function () use ($tenant) {
                $this->seedEstateRecords($tenant);
            });
        }
    }

    private function seedGeminiStaff(): void
    {
        $director = User::updateOrCreate(
            ['email' => 'director@geminisecurity.test'],
            [
                'name' => 'Andrea Case',
                'password' => Hash::make('password'),
                'console' => Console::Gemini->value,
                'status' => 'active',
            ],
        );
        $director->syncRoles([Role::DIRECTOR]);

        // Scoped to assigned sites only — the one Gemini role that is.
        $hos = User::updateOrCreate(
            ['email' => 'security@geminisecurity.test'],
            [
                'name' => 'Marlon Bailey',
                'password' => Hash::make('password'),
                'console' => Console::Gemini->value,
                'status' => 'active',
            ],
        );
        $hos->syncRoles([Role::HEAD_OF_SECURITY]);

        // Assigned to Phoenix Park only. Ocean View must be invisible to them
        // despite their being platform staff.
        if (Tenant::find('phoenixpark')) {
            EstateAssignment::updateOrCreate(
                ['user_id' => $hos->id, 'tenant_id' => 'phoenixpark'],
                ['role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true],
            );
        }
    }

    /**
     * The committee the Client Detail board draws.
     *
     * Three contacts, not two: President, Treasurer, Property Manager. The
     * Treasurer was missing, so the "Estate contacts" panel came up a row
     * short against its board — and a Treasurer is also the role the arrears
     * and payment-plan screens are written for, so an estate without one
     * cannot demonstrate them.
     *
     * NAMES ARE THE BOARD'S, and that is not decoration. These were seeded as
     * "Phoenix Park Village 1 President", which is not a name any person has:
     * it wraps to two lines where the board's does not, it reads as a
     * placeholder in a client review, and it makes the contacts panel useless
     * for judging whether the screen is right. The boards name real people, so
     * the seed does.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     *                                                                  subdomain => [mailbox, role constant, person's name]
     */
    private const COMMITTEE = [
        'phoenixpark' => [
            ['president', Role::PRESIDENT, 'Patrice Campbell'],
            ['treasurer', Role::TREASURER, 'Tracey Reid'],
            ['manager', Role::PROPERTY_MANAGER, 'Patricia Morgan'],
        ],
        'oceanview' => [
            ['president', Role::PRESIDENT, 'Lloyd Bennett'],
            ['treasurer', Role::TREASURER, 'Simone Grant'],
            ['manager', Role::PROPERTY_MANAGER, 'Errol Chin'],
        ],
    ];

    private function seedEstateCommittee(Tenant $tenant): void
    {
        $key = (string) $tenant->getTenantKey();

        // An estate nobody drew a board for still gets a full committee; only
        // the names fall back.
        $committee = self::COMMITTEE[$key] ?? [
            ['president', Role::PRESIDENT, $tenant->name.' President'],
            ['treasurer', Role::TREASURER, $tenant->name.' Treasurer'],
            ['manager', Role::PROPERTY_MANAGER, $tenant->name.' Property Manager'],
        ];

        foreach ($committee as [$mailbox, $roleName, $personName]) {
            $role = Role::named($roleName);

            $member = User::updateOrCreate(
                ['email' => "{$mailbox}@{$key}.test"],
                [
                    'name' => $personName,
                    'password' => Hash::make('password'),
                    'console' => Console::Estate->value,
                    'status' => 'active',
                ],
            );

            $member->syncRoles([$roleName]);

            EstateAssignment::updateOrCreate(
                ['user_id' => $member->id, 'tenant_id' => $key],
                ['role_id' => $role->id, 'is_active' => true],
            );
        }
    }

    /** Runs inside tenancy — every model here resolves to the estate database. */
    private function seedEstateRecords(Tenant $tenant): void
    {
        $prefix = strtoupper(substr((string) $tenant->getTenantKey(), 0, 2));

        foreach (range(1, 4) as $n) {
            $unit = Unit::updateOrCreate(
                ['reference' => "{$prefix}-{$n}A"],
                ['block' => 'Block '.chr(64 + $n), 'street' => "{$tenant->name} Drive", 'status' => 'occupied'],
            );

            $household = Household::updateOrCreate(
                ['unit_id' => $unit->id],
                ['name' => "{$tenant->name} Household {$n}", 'access_restricted' => false],
            );

            Resident::updateOrCreate(
                ['household_id' => $household->id, 'email' => "resident{$n}@{$tenant->getTenantKey()}.test"],
                [
                    'full_name' => "{$tenant->name} Resident {$n}",
                    'phone' => '876-555-'.str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT),
                    'relationship' => 'owner',
                    'is_primary' => true,
                ],
            );

            Charge::updateOrCreate(
                ['reference' => "{$prefix}-CHG-{$n}"],
                [
                    'household_id' => $household->id,
                    'description' => 'Monthly maintenance',
                    'amount_minor' => 25_000_00,
                    'currency' => 'JMD',
                    'due_on' => now()->startOfMonth()->toDateString(),
                    'status' => 'outstanding',
                ],
            );

            // Journals are append-only: insert only if absent, never update.
            if (! Journal::where('reference', "{$prefix}-JNL-{$n}")->exists()) {
                Journal::create([
                    'reference' => "{$prefix}-JNL-{$n}",
                    'memo' => 'Maintenance income',
                    'amount_minor' => 25_000_00,
                    'currency' => 'JMD',
                    'posted_on' => now()->startOfMonth()->toDateString(),
                ]);
            }
        }
    }
}
