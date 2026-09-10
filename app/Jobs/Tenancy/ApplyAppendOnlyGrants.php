<?php

declare(strict_types=1);

namespace App\Jobs\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Layer 1 of invariant 4: the estate's MySQL user must hold no UPDATE or
 * DELETE privilege on an append-only table.
 *
 * WHY THIS INVERTS 06_OVERRIDE §6's SQL
 * -------------------------------------
 * §6 specifies `REVOKE UPDATE, DELETE ON db.journals FROM user`. MySQL rejects
 * that when the privilege was granted at database level (`ON db.*`):
 *
 *     ERROR 1147 (42000): There is no such grant defined for user 'x'
 *                         on host '%' on table 'journals'
 *
 * MySQL supports partial revokes from GLOBAL to database scope, but not from
 * database to table scope. Verified empirically on MySQL 8.4.9, not assumed.
 *
 * So the privilege is never granted at database level in the first place:
 * TenancyServiceProvider strips UPDATE and DELETE from the manager's db-level
 * grant list, and this job grants them back per-table on mutable tables only.
 * The end state is exactly what §6 intended — the estate user simply cannot
 * update or delete a journal — reached by the only route MySQL allows.
 *
 * Layer 2 is the BEFORE UPDATE / BEFORE DELETE triggers created in the tenant
 * migration. Both layers, never one: a grant is per-table and easy to lose in a
 * later migration, while a trigger survives it; conversely a trigger can be
 * dropped by anyone holding TRIGGER privilege, which the grant boundary limits.
 *
 * See DECISIONS.md D-017.
 */
class ApplyAppendOnlyGrants
{
    /**
     * Tables that may only ever be inserted into.
     *
     * A correction is a new record referencing the original, never an edit.
     *
     * `journal_lines` belongs here for the same reason `journals` does, and
     * leaving it out would have made the whole protection ornamental: a header
     * nobody can edit sitting above lines anybody can edit is not an append-only
     * ledger. The amount, the account and the household on an entry all live on
     * the lines, so an estate user with UPDATE on them could restate any entry
     * ever posted while its header stood untouched.
     */
    public const APPEND_ONLY_TABLES = [
        'journals',
        'journal_lines',
    ];

    /** Privileges withheld at database level and granted back per table. */
    public const MUTATING_PRIVILEGES = ['UPDATE', 'DELETE'];

    public function __construct(protected TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        $database = $this->tenant->database()->getName();
        $username = $this->tenant->database()->getUsername();

        if (! $username) {
            // Only reachable if the manager was swapped for one that does not
            // provision per-estate users — which would already have broken
            // tenant isolation. Fail loudly rather than skip silently.
            throw new \RuntimeException(
                "Estate [{$this->tenant->getTenantKey()}] has no dedicated database user. ".
                'tenancy.database.managers must be PermissionControlledMySQLDatabaseManager. '.
                'See DECISIONS.md D-002.'
            );
        }

        // Issued as the owner: gs_app may not grant anything, and an estate
        // user may not widen its own privileges.
        $connection = DB::connection('mysql_owner');
        $privileges = implode(', ', self::MUTATING_PRIVILEGES);

        foreach ($this->mutableTables($connection, $database) as $table) {
            $connection->statement(
                "GRANT {$privileges} ON `{$database}`.`{$table}` TO `{$username}`@`%`"
            );
        }

        /*
         * Deliberately no FLUSH PRIVILEGES. GRANT applies immediately in
         * MySQL 8 — FLUSH is only needed after direct writes to the mysql.*
         * tables — and it requires RELOAD, which gs_owner has no business
         * holding just to provision an estate.
         */
    }

    /**
     * Every table in the estate database except the append-only ones.
     *
     * Read from information_schema rather than a hardcoded list so a table
     * added by a later migration is mutable by default. Append-only is the
     * exception and must be declared deliberately.
     *
     * @return list<string>
     */
    private function mutableTables(Connection $connection, string $database): array
    {
        $tables = $connection->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database, 'BASE TABLE'],
        );

        return array_values(array_diff(
            array_map(fn ($row) => $row->name, $tables),
            self::APPEND_ONLY_TABLES,
        ));
    }
}
