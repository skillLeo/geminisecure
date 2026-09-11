<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Console;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Estate\FacilitiesSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * An estate to test boards 17 to 20 against — the `CollectionsFixture` pattern,
 * with the half `CollectionsFixture` does not need: a real tenant.
 *
 * A DATABASE OF ITS OWN, built once per process by the real migrations, the real
 * chart of accounts and the REAL `FacilitiesSeeder`. Boards 17 and 19 are seeded
 * data as much as they are code — "12 open, 7 in progress, 2 overdue, 3.2 days"
 * is a claim about seventeen specific rows — so a suite that built its own queue
 * would prove nothing about the one a reviewer opens.
 *
 * IT IS NOT PHOENIX PARK, and it does not try to be. `EstateFinanceSeeder` bills
 * 450 units over six months because the figures it proves are estate-wide
 * totals; nothing here is an estate-wide total. What these tests prove is
 * arithmetic on one ticket's clock, one booking's snapshotted terms and one
 * charge's journal lines, and five lots reach all three — so five lots is what
 * gets built, and the amenity fee posted below lands in an otherwise empty
 * receivable where it can be seen on its own.
 *
 * THE FIVE LOTS ARE THE ONES THE BOARDS NAME. `FacilitiesSeeder` skips a booking
 * whose unit does not exist rather than inventing one, so Lot 47, Lot 88, Lot 63
 * and Lot 3 are here because board 19 draws them, and Lot 9 because board 17
 * puts ticket #1039 at it.
 *
 * IT ALSO PROVISIONS THE ESTATE CENTRALLY, which the collections fixture never
 * has to. The permission assertions are HTTP assertions — a role without
 * `estate.facilities.update` has to be REFUSED by the route rather than merely
 * lack an ability — and an estate route is reachable only through a real tenant,
 * resolved from a real subdomain, by a user with a real assignment to it. All
 * three are built here.
 */
final class FacilitiesFixture
{
    /** The subdomain, which is also the tenant key and the database suffix. */
    public const ESTATE = 'facilitiestest';

    /** `gs_estate_` is the tenancy prefix; the two must agree or nothing resolves. */
    public const DATABASE = 'gs_estate_'.self::ESTATE;

    /**
     * The lots boards 17 and 19 name, and nothing else.
     *
     * [reference, phase]
     *
     * @var list<array{0: string, 1: string}>
     */
    private const UNITS = [
        ['Lot 3', 'Phase 1'],
        ['Lot 9', 'Phase 1'],
        ['Lot 47', 'Phase 2'],
        ['Lot 63', 'Phase 3'],
        ['Lot 88', 'Phase 4'],
    ];

    private static bool $built = false;

    private static bool $provisioned = false;

    /**
     * Point the tenant connection at the fixture, building it on first use.
     *
     * The config is re-applied on every call rather than once, because Laravel
     * refreshes the application between tests and the connection would otherwise
     * fall back to the platform database mid-suite. It is re-applied after an
     * HTTP request too: tenancy leaves the tenant connection pointed at this
     * same database, but it is a connection of tenancy's own making and purging
     * ours keeps one description of where these rows live.
     */
    public static function boot(): void
    {
        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.mysql'),
                ['database' => self::DATABASE],
            ),
            'database.default' => 'tenant',
        ]);

        DB::purge('tenant');

        if (self::$built) {
            return;
        }

        $owner = DB::connection('mysql_owner');
        $owner->statement('DROP DATABASE IF EXISTS `'.self::DATABASE.'`');
        $owner->statement('CREATE DATABASE `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('tenant');

        Artisan::call('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
            '--force' => true,
        ]);

        (new ChartOfAccountsSeeder)->run();

        self::buildEstate();

        (new FacilitiesSeeder)->run();

        self::$built = true;
    }

    /**
     * The central half: the permission matrix and the estate itself.
     *
     * Committed rather than rolled back, and once per process. The matrix is the
     * REAL one — transcribed from board 24 and amended by Ruling 1 — because
     * what the permission assertions are about is which cell the Property
     * Manager holds on Dues & ledger, and a hand-built grid here would pass
     * while the seeded one locked the wrong role out of the wrong module.
     */
    public static function platform(): Tenant
    {
        self::boot();

        /*
         * SEEDED ON THE CELL, NOT ON THE ROLE — the rule `EstateResidentAccessTest`
         * learned from D-053. A test database seeded before D-086 gave the
         * Facilities row its two Approver cells already HAS a Treasurer, so a
         * check for the role would leave the matrix a version behind, and every
         * forfeit assertion would fail as a 403 that looked like a broken route
         * rather than a stale fixture.
         */
        $current = Role::query()
            ->where('name', Role::PROPERTY_MANAGER)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.facilities.approve'))
            ->exists();

        if (! $current) {
            Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
        }

        // Per-process and would otherwise hold whatever a previous test file
        // left in it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $estate = Tenant::query()->find(self::ESTATE);

        if (! $estate instanceof Tenant) {
            /*
             * WITHOUT EVENTS, deliberately. `TenantCreated` runs CreateDatabase,
             * MigrateDatabase and the append-only grants — the real provisioning
             * pipeline — and this database is already built above. Letting the
             * pipeline run would fail on a database that exists and, if it did
             * not, would leave a second MySQL user behind for a fixture that is
             * dropped and rebuilt on every run.
             */
            $estate = Tenant::withoutEvents(static function (): Tenant {
                $estate = new Tenant;

                // Phoenix Park, because D-038 leaves exactly two estates on this
                // platform and an invented third would reach a screen.
                $estate->forceFill([
                    'id' => self::ESTATE,
                    'name' => 'Phoenix Park',
                    'status' => 'active',
                ])->save();

                return $estate;
            });
        }

        /*
         * The BARE SUBDOMAIN, not the full hostname, exactly as
         * `EstateProvisioner` stores it: `InitializeTenancyBySubdomain` strips
         * the central domain off the host and looks up what remains. Without
         * this row the estate is unidentifiable and every assertion below would
         * be made against a 500.
         */
        if (! $estate->domains()->where('domain', self::ESTATE)->exists()) {
            $estate->domains()->create(['domain' => self::ESTATE]);
        }

        self::$provisioned = true;

        return $estate;
    }

    /** Whether the estate has been provisioned centrally in this process. */
    public static function isProvisioned(): bool
    {
        return self::$provisioned;
    }

    /**
     * A user holding exactly one estate role, assigned to this estate.
     *
     * BOTH HALVES ARE NEEDED AND THEY ANSWER DIFFERENT QUESTIONS. The role
     * decides what they may do; the assignment decides whether they may reach
     * this estate at all — `EnsureEstateAccess` 404s an estate user with no
     * assignment, which would make every permission assertion below pass for the
     * wrong reason.
     */
    public static function viewer(string $roleName): User
    {
        $estate = self::platform();

        $user = User::factory()->create([
            'console' => Console::Estate->value,
            'status' => 'active',
        ]);

        $user->syncRoles([$roleName]);

        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $estate->getTenantKey(),
            'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    /**
     * A user holding one GEMINI role, with no estate assignment.
     *
     * The platform console is not an estate's, so there is nothing to assign
     * them to: a Director reaches every estate by role, and the cross-tenant
     * exports this fixture is used for are exactly that reach.
     */
    public static function geminiViewer(string $roleName): User
    {
        self::platform();

        $user = User::factory()->create([
            'console' => Console::Gemini->value,
            'status' => 'active',
        ]);

        $user->syncRoles([$roleName]);

        return $user->fresh();
    }

    /** Where a screen lives on this estate's own hostname. */
    public static function url(string $path): string
    {
        return 'http://'.self::ESTATE.'.'.config('app.estate_domain').'/'.ltrim($path, '/');
    }

    /** The unit at this reference. Fails loudly rather than returning null. */
    public static function unit(string $reference): Unit
    {
        self::boot();

        return Unit::query()->where('reference', $reference)->firstOrFail();
    }

    /** One ticket by the number board 17 prints and board 18's URL uses. */
    public static function ticket(int $number): MaintenanceTicket
    {
        self::boot();

        return MaintenanceTicket::query()->where('number', $number)->firstOrFail();
    }

    /** One amenity by the name board 20 draws on its card. */
    public static function amenity(string $name): Amenity
    {
        self::boot();

        return Amenity::query()->where('name', $name)->firstOrFail();
    }

    /** One vendor from the register board 26 keeps. */
    public static function vendor(string $name): Vendor
    {
        self::boot();

        return Vendor::query()->where('name', $name)->firstOrFail();
    }

    /** The seeded booking for one amenity at one lot — board 19's own rows. */
    public static function booking(string $amenity, string $unitReference): AmenityBooking
    {
        self::boot();

        return AmenityBooking::query()
            ->where('amenity_id', self::amenity($amenity)->id)
            ->where('unit_id', self::unit($unitReference)->id)
            ->orderBy('starts_at')
            ->firstOrFail();
    }

    /** Five lots, at the phases the boards put them in. */
    private static function buildEstate(): void
    {
        foreach (self::UNITS as [$reference, $phase]) {
            Unit::create([
                'reference' => $reference,
                'block' => $phase,
                'street' => $phase.' Drive',
                'type' => 'residential',
                'status' => 'occupied',
            ]);
        }
    }
}
