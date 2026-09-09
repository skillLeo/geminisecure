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
     * The names the approved boards give these clients.
     *
     * An estate is created by `estate:provision <subdomain> <name>`, and the
     * short names used there ("Phoenix Park") are not the names the design
     * shows ("Phoenix Park Village 1"). The name is the first thing on the
     * client detail screen and it sizes the hero card, which sizes everything
     * beside it — so a shorter name moves the whole action column and reads as
     * a layout fault when comparing against the board.
     *
     * Applied here rather than at provision time so an estate provisioned from
     * the boards keeps its board name however it was created.
     *
     * @var array<string, string>
     */
    private const BOARD_NAMES = [
        'phoenixpark' => 'Phoenix Park Village 1',
        'oceanview' => 'Ocean View Gardens',
    ];

    public function run(): void
    {
        $this->seedGeminiStaff();

        foreach (self::BOARD_NAMES as $subdomain => $name) {
            Tenant::find($subdomain)?->forceFill(['name' => $name])->save();
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
