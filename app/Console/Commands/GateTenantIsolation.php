<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Exceptions\MissingTenantContextException;
use App\Jobs\Estate\RecalculateHouseholdStanding;
use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Phase 1 acceptance gate: 06_OVERRIDE section 7, steps 3 to 9.
 *
 *   php artisan gate:isolation
 *
 * Exits non-zero on any failure so it is usable in CI rather than something a
 * human has to read carefully. Every assertion states what it proves, because
 * a gate nobody understands is a gate nobody maintains.
 *
 * Deliberately pure ASCII. This file is read and rewritten by tooling on
 * Windows, where a UTF-8 round-trip can double-encode the content and place a
 * BOM ahead of the strict_types declaration, which breaks the file outright.
 */
class GateTenantIsolation extends Command
{
    protected $signature = 'gate:isolation {--estate-a=phoenixpark} {--estate-b=oceanview}';

    protected $description = 'Prove tenant isolation and append-only enforcement at the database layer';

    private int $failures = 0;

    public function handle(): int
    {
        $a = Tenant::findOrFail($this->option('estate-a'));
        $b = Tenant::findOrFail($this->option('estate-b'));

        $this->line('');
        $this->line('=====================================================================');
        $this->line(' PHASE 1 GATE - tenant isolation, RBAC and append-only records');
        $this->line('=====================================================================');

        $this->step3CrossEstateRoutesDenied($a, $b);
        $this->step4CrossEstateQueryFailsOnGrant($a, $b);
        $this->step5NoWildcardGrant();
        $this->step6QueuedJobWithoutTenantThrows();
        $this->step7JournalsAppendOnly($a);
        $this->step7bAuditLogAppendOnly();
        $this->step8NavigationMatchesMatrix();
        $this->step9IdentityIsCentral($a);

        $this->line('');

        if ($this->failures > 0) {
            $this->error(" GATE FAILED - {$this->failures} assertion(s) did not hold");

            return self::FAILURE;
        }

        $this->info(' GATE PASSED - every assertion held');

        return self::SUCCESS;
    }

    /**
     * Step 3 - authenticated as a committee member of estate A, request a
     * known record of estate B through every route. Any 200 fails the build.
     */
    private function step3CrossEstateRoutesDenied(Tenant $a, Tenant $b): void
    {
        $this->section("STEP 3 - {$a->getTenantKey()} user requests {$b->getTenantKey()} records by id");

        $user = User::where('email', "president@{$a->getTenantKey()}.test")->first();

        if (! $user) {
            $this->assert(false, 'committee user exists to probe with', 'run DemoDataSeeder first');

            return;
        }

        $this->line("  authenticated as {$user->email} (".$a->getTenantKey().' committee)');

        /*
         * Positive control, first and deliberately.
         *
         * Without it this whole step passes when the application is simply
         * broken: every route 404s, the assertions all hold, and the gate
         * reports an isolation it never demonstrated. Proving the user CAN
         * reach their own estate is what gives the denials below any meaning.
         */
        $ownId = $this->existingRecordIds($a)['residents'] ?? null;

        if ($ownId !== null) {
            $status = $this->requestAs($user, $a, "records/residents/{$ownId}");

            $this->assert(
                $status === 200,
                "CONTROL - same user CAN read their own estate resident {$ownId}, got 200",
                "got {$status}; the denials below prove nothing if this fails",
            );
        }

        // Ids that genuinely exist in estate B, so a leak returns a plausible
        // record rather than a 404 for a row that was never there.
        foreach ($this->existingRecordIds($b) as $resource => $id) {
            if ($id === null) {
                $this->line("  SKIP {$resource}: no seeded record in {$b->getTenantKey()}");

                continue;
            }

            $status = $this->requestAs($user, $b, "records/{$resource}/{$id}");

            /*
             * 404 specifically, not merely "not 200".
             *
             * A 500 is also non-200 while saying nothing about whether the
             * boundary held; it could be an unrelated crash that happens to
             * precede the check. A 403 would confirm the estate and the record
             * id both exist, which is an enumeration oracle between competing
             * communities. The specified behaviour is 404, so this asserts 404.
             */
            $this->assert(
                $status === 404,
                sprintf('GET records/%s/%s from the wrong estate, got %d', $resource, $id, $status),
                $status === 200
                    ? 'LEAK: the record was returned across the estate boundary'
                    : "expected 404, got {$status}",
            );
        }
    }

    /** Issues one authenticated request against an estate subdomain. */
    private function requestAs(User $user, Tenant $tenant, string $path): int
    {
        Auth::guard('web')->login($user);

        $host = $tenant->getTenantKey().'.'.config('app.estate_domain');
        $response = app(HttpKernel::class)->handle(
            Request::create("http://{$host}/{$path}", 'GET')
        );

        Auth::guard('web')->logout();
        tenancy()->end();

        return $response->getStatusCode();
    }

    /**
     * The real ids seeded into an estate, so the probe asks for records that
     * exist rather than ones that would 404 regardless.
     *
     * @return array<string, int|null>
     */
    private function existingRecordIds(Tenant $tenant): array
    {
        $ids = [];

        $tenant->run(function () use (&$ids) {
            foreach (['residents', 'households', 'units', 'charges', 'journals'] as $table) {
                $ids[$table] = DB::connection('tenant')->table($table)->min('id');
            }
        });

        return $ids;
    }

    /**
     * Step 4 - a query against another estate must fail on a GRANT error, not
     * return an empty set. An empty set would mean the grant is too wide and
     * only application logic is keeping the estates apart.
     */
    private function step4CrossEstateQueryFailsOnGrant(Tenant $a, Tenant $b): void
    {
        $this->section("STEP 4 - from {$a->getTenantKey()} context, read {$b->getTenantKey()} directly");

        tenancy()->initialize($a);

        $foreignDb = $b->database()->getName();
        $result = null;
        $error = null;

        try {
            $result = DB::connection('tenant')->select("SELECT * FROM `{$foreignDb}`.`residents`");
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        tenancy()->end();

        if ($error !== null) {
            $this->line('  '.str($error)->limit(200));
        }

        $this->assert(
            $error !== null && str_contains($error, 'denied'),
            'cross-estate read denied at the GRANT layer',
            $result !== null
                ? 'LEAK: query SUCCEEDED and returned '.count($result).' row(s)'
                : "query failed, but not on a grant error: {$error}",
        );

        $this->assert(
            $result === null,
            'no rows returned across the estate boundary',
            'a result set was returned',
        );
    }

    /** Step 5 - gs_app must hold no grant on any estate database. */
    private function step5NoWildcardGrant(): void
    {
        $this->section('STEP 5 - gs_app holds no grant on any estate database');

        foreach (['localhost', '127.0.0.1'] as $host) {
            $grants = DB::connection('mysql_owner')->select("SHOW GRANTS FOR 'gs_app'@'{$host}'");

            foreach ($grants as $row) {
                $this->line('  '.reset($row));
            }

            $offending = array_filter(
                array_map(fn ($r) => (string) reset($r), $grants),
                fn (string $g) => str_contains($g, 'gs_estate'),
            );

            $this->assert(
                $offending === [],
                "gs_app@{$host} holds no gs_estate grant",
                'FOUND: '.implode(' | ', $offending),
            );
        }
    }

    /**
     * Step 6 - a queued job that writes estate data must throw when it runs
     * with no tenant context, never silently default to one.
     */
    private function step6QueuedJobWithoutTenantThrows(): void
    {
        $this->section('STEP 6 - queued job with no tenant context must throw');

        tenancy()->end();

        $threw = null;

        try {
            (new RecalculateHouseholdStanding(1))->handle();
        } catch (Throwable $e) {
            $threw = $e;
        }

        if ($threw !== null) {
            $this->line('  '.class_basename($threw).': '.str($threw->getMessage())->limit(130));
        }

        $this->assert(
            $threw instanceof MissingTenantContextException,
            'job refuses to run without tenant context',
            $threw === null
                ? 'JOB RAN - it would have written to whichever database was default'
                : 'threw the wrong exception: '.get_class($threw),
        );
    }

    /**
     * Step 7 - journals must reject UPDATE twice over: once on the withheld
     * grant, and again on the trigger were the grant ever restored.
     */
    private function step7JournalsAppendOnly(Tenant $a): void
    {
        $this->section("STEP 7 - journals are append-only in {$a->getTenantKey()}");

        $db = $a->database()->getName();
        $user = $a->database()->getUsername();

        $grants = DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$user}`@`%`");
        $journalGrant = array_filter(
            array_map(fn ($r) => (string) reset($r), $grants),
            fn (string $g) => str_contains($g, 'journals'),
        );

        $this->assert(
            $journalGrant === [],
            'LAYER 1 - estate user holds no table grant on journals',
            'FOUND: '.implode(' | ', $journalGrant),
        );

        /*
         * Seed one row to attempt the update against - and it has to be a REAL
         * entry now.
         *
         * This probe used to insert a bare header with an amount and no lines,
         * which was all a journal was before the ledger existed. The double
         * entry migration made that impossible on purpose: journals_must_balance
         * refuses a header whose lines do not sum, and a header with no lines
         * sums to nothing. So the probe posts two balanced lines against the
         * first two accounts in the estate's chart, then the header - which is
         * the order every write to this ledger takes.
         *
         * An estate with no chart of accounts yet cannot be probed this way, and
         * the step says so rather than passing on an entry it never created.
         */
        tenancy()->initialize($a);

        $accounts = DB::connection('tenant')->table('accounts')->orderBy('code')->limit(2)->pluck('id')->all();

        if (count($accounts) < 2) {
            tenancy()->end();

            $this->assert(
                false,
                'a balanced entry exists to attempt an edit against',
                "estate {$a->getTenantKey()} has fewer than two accounts; run the chart of accounts seeder",
            );

            return;
        }

        if (! DB::connection('tenant')->table('journals')->where('id', 999)->exists()) {
            DB::connection('tenant')->table('journal_lines')->insert([
                ['entry_ref' => 'GATE-PROBE', 'account_id' => $accounts[0], 'line_no' => 1, 'debit_minor' => 10000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now()],
                ['entry_ref' => 'GATE-PROBE', 'account_id' => $accounts[1], 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 10000, 'currency' => 'JMD', 'created_at' => now()],
            ]);

            DB::connection('tenant')->table('journals')->insert([
                'id' => 999,
                'reference' => 'GATE-PROBE',
                'memo' => 'gate probe',
                'source' => 'manual',
                'amount_minor' => 10000,
                'currency' => 'JMD',
                'posted_on' => now()->toDateString(),
                'created_at' => now(),
            ]);
        }

        $error = null;

        try {
            DB::connection('tenant')->statement("UPDATE journals SET memo = 'edited' WHERE id = 999");
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        tenancy()->end();

        if ($error !== null) {
            $this->line('  '.str($error)->limit(160));
        }

        $this->assert($error !== null, 'UPDATE on journals rejected for the estate user', 'the ledger is mutable');

        // Layer 2, proven independently: attempt the same write as the OWNER,
        // which does hold UPDATE on the table. Only the trigger can stop this.
        $triggerError = null;

        try {
            DB::connection('mysql_owner')->statement("UPDATE `{$db}`.`journals` SET memo = 'edited' WHERE id = 999");
        } catch (Throwable $e) {
            $triggerError = $e->getMessage();
        }

        if ($triggerError !== null) {
            $this->line('  '.str($triggerError)->limit(160));
        }

        $this->assert(
            $triggerError !== null && str_contains($triggerError, 'append-only'),
            'LAYER 2 - trigger rejects UPDATE even for a privileged user',
            'the schema owner edited a posted journal',
        );

        $deleteError = null;

        try {
            DB::connection('mysql_owner')->statement("DELETE FROM `{$db}`.`journals` WHERE id = 999");
        } catch (Throwable $e) {
            $deleteError = $e->getMessage();
        }

        $this->assert(
            $deleteError !== null && str_contains($deleteError, 'append-only'),
            'LAYER 2 - trigger rejects DELETE even for a privileged user',
            'a posted journal was deleted',
        );

        $this->line('  append-only tables: '.implode(', ', ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));

        $this->step7cEveryMutableTableIsActuallyMutable();
    }

    /**
     * Which tables in one estate database the estate's own user may UPDATE.
     *
     * ASKED WITH `SHOW GRANTS`, AND THE TWO OBVIOUS SOURCES BOTH FAILED.
     * `information_schema.TABLE_PRIVILEGES` shows only what the CONNECTED user
     * can see, and the schema owner is not the grantee, so it returned nothing —
     * which the first version of this check read as "forty-two tables are
     * ungranted" while every write on the platform was plainly working. Reading
     * `mysql.tables_priv` directly is refused too: `gs_owner` holds no SELECT on
     * the `mysql` schema, and it should not.
     *
     * `SHOW GRANTS FOR user@%` works, because the owner holds GRANT OPTION on
     * these databases — the same privilege that let it issue the grants in the
     * first place. Its output is text, so this parses it, which is the price of
     * asking the only source that will answer.
     *
     * @return list<string>
     */
    private function tablesGrantedUpdate(string $username, string $database): array
    {
        $rows = DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$username}`@`%`");

        $tables = [];

        foreach ($rows as $row) {
            // One column, named after the user, so it cannot be addressed by a
            // fixed key — take whatever the row holds.
            $line = (string) (array_values((array) $row)[0] ?? '');

            if (! preg_match('/^GRANT (.+?) ON `'.preg_quote($database, '/').'`\.`(.+?)` TO /', $line, $m)) {
                continue;
            }

            if (str_contains(strtoupper($m[1]), 'UPDATE')) {
                $tables[] = $m[2];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Step 7c — the OTHER half of the grant model, and the half that broke.
     *
     * Everything above proves the append-only tables are locked. Nothing proved
     * that the rest are USABLE, and that is the failure this platform actually
     * hit: UPDATE and DELETE are withheld at database level and granted back per
     * table (D-017), so a migration that adds a table leaves it with no grant at
     * all. Reads work. Inserts work. Only an update fails, and only when
     * somebody happens to run one — twice in one day here, on the payroll tables
     * and then on the notices.
     *
     * `DatabaseMigrated` now re-grants automatically, and this is what proves it
     * worked. A gate that only ever checked the locks would keep passing while
     * every new table in the estate was quietly read-only.
     */
    private function step7cEveryMutableTableIsActuallyMutable(): void
    {
        $this->newLine();
        $this->line('--- STEP 7c - every mutable estate table can actually be written -------');

        $estate = Tenant::estates()->first();

        if ($estate === null) {
            $this->assert(false, 'an estate exists to check grants on', 'no estates are provisioned');

            return;
        }

        $database = $estate->database()->getName();
        $username = $estate->database()->getUsername();

        /*
         * Read from the server's own grant tables rather than by attempting a
         * write per table. An UPDATE probe against forty tables would have to
         * invent a row to update in each, and a table with no rows would pass
         * for the wrong reason.
         */
        $granted = $this->tablesGrantedUpdate($username, $database);

        $tables = collect(DB::connection('mysql_owner')->select('
            SELECT TABLE_NAME AS name
              FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?
        ', [$database, 'BASE TABLE']))
            ->pluck('name')
            ->reject(static fn (string $table): bool => $table === 'migrations')
            ->all();

        $shouldBeMutable = array_values(array_diff($tables, ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));
        $missing = array_values(array_diff($shouldBeMutable, $granted));

        if ($missing !== []) {
            $this->line('  ungranted: '.implode(', ', $missing));
        }

        $this->assert(
            $missing === [],
            count($shouldBeMutable).' mutable tables all carry UPDATE for the estate user',
            count($missing).' table(s) were added by a migration and never granted — run grants:estates',
        );

        /*
         * And the converse, which is the invariant itself: nothing on the
         * append-only list may have been granted UPDATE by accident. A table
         * added to that list AFTER an estate was provisioned would still be
         * carrying the grant it was given when it was ordinary.
         */
        $wronglyGranted = array_values(array_intersect(ApplyAppendOnlyGrants::APPEND_ONLY_TABLES, $granted));

        if ($wronglyGranted !== []) {
            $this->line('  wrongly granted: '.implode(', ', $wronglyGranted));
        }

        $this->assert(
            $wronglyGranted === [],
            'no append-only table carries UPDATE for the estate user',
            implode(', ', $wronglyGranted).' is append-only and still holds a grant',
        );
    }

    /**
     * Step 7b - the CENTRAL audit log is append-only too.
     *
     * "Not editable by anyone including platform staff" has to survive a
     * Director, who holds Full on this module. Both layers are proven the same
     * way as journals: the grant for the application user, and the trigger
     * against a privileged connection that does hold UPDATE.
     */
    private function step7bAuditLogAppendOnly(): void
    {
        $this->section('STEP 7b - audit_log is append-only in gs_platform');

        $database = config('database.connections.mysql.database');
        $appUser = config('database.connections.mysql.username');

        foreach (['localhost', '127.0.0.1'] as $host) {
            $grants = array_map(
                fn ($r) => (string) reset($r),
                DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$appUser}`@`{$host}`"),
            );

            // A database-wide UPDATE/DELETE grant would silently cover
            // audit_log and make the withheld-grant layer meaningless.
            $wideGrant = array_filter(
                $grants,
                fn (string $g) => str_contains($g, "`{$database}`.*")
                    && (str_contains($g, 'UPDATE') || str_contains($g, 'DELETE')),
            );

            $this->assert(
                $wideGrant === [],
                "LAYER 1 - {$appUser}@{$host} holds no database-wide UPDATE or DELETE",
                'FOUND: '.implode(' | ', $wideGrant),
            );

            $auditGrant = array_filter($grants, fn (string $g) => str_contains($g, 'audit_log'));

            $this->assert(
                $auditGrant === [],
                "LAYER 1 - {$appUser}@{$host} holds no table grant on audit_log",
                'FOUND: '.implode(' | ', $auditGrant),
            );
        }

        // Seed one row to attempt the update against.
        DB::connection('mysql_owner')->table('audit_log')->insertOrIgnore([
            'id' => 999999,
            'action' => 'gate.probe',
            'actor_name' => 'Gate',
            'created_at' => now(),
        ]);

        $updateError = null;

        try {
            DB::connection('mysql_owner')
                ->statement("UPDATE `{$database}`.`audit_log` SET action = 'tampered' WHERE id = 999999");
        } catch (Throwable $e) {
            $updateError = $e->getMessage();
        }

        if ($updateError !== null) {
            $this->line('  '.str($updateError)->limit(160));
        }

        $this->assert(
            $updateError !== null && str_contains($updateError, 'append-only'),
            'LAYER 2 - trigger rejects UPDATE even for the schema owner',
            'an audit entry was edited',
        );

        $deleteError = null;

        try {
            DB::connection('mysql_owner')
                ->statement("DELETE FROM `{$database}`.`audit_log` WHERE id = 999999");
        } catch (Throwable $e) {
            $deleteError = $e->getMessage();
        }

        $this->assert(
            $deleteError !== null && str_contains($deleteError, 'append-only'),
            'LAYER 2 - trigger rejects DELETE even for the schema owner',
            'an audit entry was deleted',
        );
    }

    /** Step 8 - each role's navigation contains only its permitted modules. */
    private function step8NavigationMatchesMatrix(): void
    {
        $this->section('STEP 8 - navigation contains only permitted modules, per role');

        $roles = Role::with('moduleAccess.module')->orderBy('console')->orderBy('sort')->get();

        $this->assert($roles->count() === 13, '13 roles seeded (6 Gemini + 7 estate)', "found {$roles->count()}");

        foreach ($roles as $role) {
            $nav = $role->navigableModules();

            $leaked = $nav->filter(function ($module) use ($role) {
                $cell = $role->moduleAccess->firstWhere('module_id', $module->id);

                return $cell === null || $cell->level === AccessLevel::None;
            });

            // Ruling 1: a locked financial module must never reach the
            // Property Manager, by any route including a hand-edited matrix.
            $lockedLeak = $role->name === Role::PROPERTY_MANAGER
                ? $nav->filter(fn ($m) => $m->is_locked_financial)
                : collect();

            $this->assert(
                $leaked->isEmpty() && $lockedLeak->isEmpty(),
                sprintf('%-24s %2d modules', $role->label, $nav->count()),
                $lockedLeak->isNotEmpty()
                    ? 'LOCKED FINANCIAL LEAK: '.$lockedLeak->pluck('key')->implode(', ')
                    : 'level none appeared in navigation: '.$leaked->pluck('key')->implode(', '),
            );
        }
    }

    /**
     * Step 9 - roles, permissions and users are central and never duplicated
     * into an estate database, or two estates could drift apart.
     */
    private function step9IdentityIsCentral(Tenant $a): void
    {
        $this->section('STEP 9 - identity lives in gs_platform, not per estate');

        $identityTables = ['users', 'roles', 'permissions', 'role_module_access', 'modules'];

        $central = DB::connection('mysql_owner')->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [config('database.connections.mysql.database')],
        );
        $centralNames = array_map(fn ($r) => $r->name, $central);

        $missing = array_diff($identityTables, $centralNames);

        $this->assert(
            $missing === [],
            'every identity table present in gs_platform',
            'MISSING: '.implode(', ', $missing),
        );

        $estateTables = [];
        $a->run(function () use (&$estateTables, $a) {
            $rows = DB::connection('tenant')->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                [$a->database()->getName()],
            );
            $estateTables = array_map(fn ($r) => $r->name, $rows);
        });

        $this->line('  estate tables: '.implode(', ', $estateTables));

        $duplicated = array_intersect($identityTables, $estateTables);

        $this->assert(
            $duplicated === [],
            'no identity table duplicated into the estate database',
            'DUPLICATED: '.implode(', ', $duplicated),
        );
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("--- {$title} ".str_repeat('-', max(0, 66 - strlen($title))));
    }

    private function assert(bool $held, string $proves, string $failureDetail): void
    {
        if ($held) {
            $this->line("  <fg=green>PASS</> {$proves}");

            return;
        }

        $this->failures++;
        $this->line("  <fg=red>FAIL</> {$proves} - {$failureDetail}");
    }
}
