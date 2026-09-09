<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Full demonstration dataset, in dependency order.
 *
 * Order is not cosmetic:
 *   RbacMatrix  first - roles must exist before any user can be assigned one
 *   DemoData    next  - creates users and per-estate records
 *   GuardWorkforce    - guards need estates to be posted at
 *   Billing / Payroll - both need guards and estates
 *   AuditLog    last  - references the director created above
 *
 * Estates themselves are NOT seeded here. Provisioning creates a database and
 * a MySQL user per estate, which is a deliberate operation rather than
 * something a seeder should do silently:
 *
 *     php artisan estate:provision phoenixpark "Phoenix Park" --status=active
 *     php artisan estate:provision oceanview  "Ocean View"   --status=active
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RbacMatrixSeeder::class,
            DemoDataSeeder::class,
            GuardWorkforceSeeder::class,
            // Rosters, patrol tours and the requests inbox. After the
            // workforce: every row here hangs off a guard and a post.
            DispatchOperationsSeeder::class,
            BillingSeeder::class,
            PayrollSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
