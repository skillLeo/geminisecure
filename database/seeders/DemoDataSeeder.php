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
    public function run(): void
    {
        $this->seedGeminiStaff();

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

    private function seedEstateCommittee(Tenant $tenant): void
    {
        $key = $tenant->getTenantKey();

        $president = User::updateOrCreate(
            ['email' => "president@{$key}.test"],
            [
                'name' => $tenant->name.' President',
                'password' => Hash::make('password'),
                'console' => Console::Estate->value,
                'status' => 'active',
            ],
        );
        $president->syncRoles([Role::PRESIDENT]);

        EstateAssignment::updateOrCreate(
            ['user_id' => $president->id, 'tenant_id' => $key],
            ['role_id' => Role::named(Role::PRESIDENT)->id, 'is_active' => true],
        );

        $manager = User::updateOrCreate(
            ['email' => "manager@{$key}.test"],
            [
                'name' => $tenant->name.' Property Manager',
                'password' => Hash::make('password'),
                'console' => Console::Estate->value,
                'status' => 'active',
            ],
        );
        $manager->syncRoles([Role::PROPERTY_MANAGER]);

        EstateAssignment::updateOrCreate(
            ['user_id' => $manager->id, 'tenant_id' => $key],
            ['role_id' => Role::named(Role::PROPERTY_MANAGER)->id, 'is_active' => true],
        );
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
