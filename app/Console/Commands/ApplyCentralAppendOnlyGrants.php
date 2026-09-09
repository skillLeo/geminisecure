<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Layer 1 of invariant 4 for the CENTRAL database.
 *
 *   php artisan grants:append-only
 *
 * The estate equivalent runs automatically at provisioning
 * (App\Jobs\Tenancy\ApplyAppendOnlyGrants). gs_platform has no provisioning
 * event, so this is a command, run once after migrating and again whenever a
 * migration adds a table.
 *
 * WHY THE GRANT IS INVERTED RATHER THAN REVOKED
 * ---------------------------------------------
 * MySQL cannot revoke a table-scoped privilege from a database-scoped grant:
 *
 *     ERROR 1147 (42000): There is no such grant defined for user 'gs_app'
 *                         on host 'localhost' on table 'audit_log'
 *
 * So UPDATE and DELETE are withheld from gs_app's gs_platform.* grant and
 * granted back per table on everything that is not append-only. Verified on
 * MySQL 8.4.9. See DECISIONS.md D-017.
 *
 * Idempotent, and safe to re-run after every migration.
 */
class ApplyCentralAppendOnlyGrants extends Command
{
    protected $signature = 'grants:append-only {--dry-run : list the changes without applying them}';

    protected $description = 'Withhold UPDATE and DELETE on append-only central tables from the application user';

    /** Central tables that may only ever be inserted into. */
    public const APPEND_ONLY_TABLES = [
        'audit_log',
    ];

    private const MUTATING = ['UPDATE', 'DELETE'];

    public function handle(): int
    {
        $connection = DB::connection('mysql_owner');
        $database = config('database.connections.mysql.database');
        $appUser = config('database.connections.mysql.username');
        $dryRun = (bool) $this->option('dry-run');

        $tables = collect($connection->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database, 'BASE TABLE'],
        ))->pluck('name');

        $mutable = $tables->reject(fn (string $t) => in_array($t, self::APPEND_ONLY_TABLES, true));
        $privileges = implode(', ', self::MUTATING);

        $this->info("Database: {$database}   Application user: {$appUser}");
        $this->line('  append-only: '.implode(', ', self::APPEND_ONLY_TABLES));
        $this->line('  mutable    : '.$mutable->count().' tables');

        if ($dryRun) {
            $this->warn('  dry run - nothing applied');

            return self::SUCCESS;
        }

        foreach (['localhost', '127.0.0.1'] as $host) {
            /*
             * Drop the database-wide grant first, then rebuild it: SELECT and
             * INSERT everywhere, UPDATE and DELETE only on mutable tables.
             * Revoking the whole grant and re-granting is the only ordering
             * MySQL permits, since the narrow revoke is what raises 1147.
             */
            $connection->statement("REVOKE ALL PRIVILEGES ON `{$database}`.* FROM `{$appUser}`@`{$host}`");
            $connection->statement("GRANT SELECT, INSERT ON `{$database}`.* TO `{$appUser}`@`{$host}`");

            foreach ($mutable as $table) {
                $connection->statement(
                    "GRANT {$privileges} ON `{$database}`.`{$table}` TO `{$appUser}`@`{$host}`"
                );
            }

            $this->line("  <fg=green>OK</> {$appUser}@{$host}");
        }

        $this->info('Applied. audit_log is now insert-only for the application user.');

        return self::SUCCESS;
    }
}
