<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Phase 1 acceptance gate, database layer (06_OVERRIDE §7 steps 4, 5, 7).
 *
 *   php artisan gate:isolation
 *
 * Exits non-zero on any failure so it is usable in CI rather than something a
 * human has to read carefully. Every assertion states what it proves, because
 * a gate nobody understands is a gate nobody maintains.
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
        $this->line(' PHASE 1 GATE — tenant isolation and append-only, at the DB layer');
        $this->line('=====================================================================');

        $this->step5NoWildcardGrant();
        $this->step4CrossEstateQueryFailsOnGrant($a, $b);
        $this->step7JournalsAppendOnly($a);

        $this->line('');

        if ($this->failures > 0) {
            $this->error(" GATE FAILED — {$this->failures} assertion(s) did not hold");

            return self::FAILURE;
        }

        $this->info(' GATE PASSED — every assertion held');

        return self::SUCCESS;
    }

    /** §7 step 5 — gs_app must hold no wildcard grant on estate databases. */
    private function step5NoWildcardGrant(): void
    {
        $this->section('STEP 5 · gs_app holds no grant on any estate database');

        foreach (['localhost', '127.0.0.1'] as $host) {
            $grants = DB::connection('mysql_owner')
                ->select("SHOW GRANTS FOR 'gs_app'@'{$host}'");

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
     * §7 step 4 — a query against another estate must fail on a GRANT error,
     * not return an empty set. An empty set would mean the grant is too wide
     * and only application logic is keeping the estates apart.
     */
    private function step4CrossEstateQueryFailsOnGrant(Tenant $a, Tenant $b): void
    {
        $this->section("STEP 4 · from {$a->getTenantKey()} context, read {$b->getTenantKey()} directly");

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
            $this->line('  '.str($error)->limit(240));
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

    /**
     * §7 step 7 — journals must reject UPDATE twice over: once on the missing
     * grant, and again on the trigger if the grant were ever restored.
     */
    private function step7JournalsAppendOnly(Tenant $a): void
    {
        $this->section("STEP 7 · journals are append-only in {$a->getTenantKey()}");

        $db = $a->database()->getName();
        $user = $a->database()->getUsername();

        // Layer 1: the estate user holds no UPDATE/DELETE on journals.
        $grants = DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$user}`@`%`");
        $journalGrant = array_filter(
            array_map(fn ($r) => (string) reset($r), $grants),
            fn (string $g) => str_contains($g, 'journals'),
        );

        foreach ($journalGrant as $g) {
            $this->line('  '.$g);
        }

        $this->assert(
            $journalGrant === [],
            'LAYER 1 — estate user holds no table grant on journals',
            'FOUND: '.implode(' | ', $journalGrant),
        );

        // Seed one row to update against.
        tenancy()->initialize($a);
        DB::connection('tenant')->table('journals')->insertOrIgnore([
            'id' => 1,
            'reference' => 'GATE-PROBE-1',
            'memo' => 'gate probe',
            'amount_minor' => 100_00,
            'currency' => 'JMD',
            'posted_on' => now()->toDateString(),
            'created_at' => now(),
        ]);

        $error = null;

        try {
            DB::connection('tenant')->statement("UPDATE journals SET memo = 'edited' WHERE id = 1");
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        tenancy()->end();

        if ($error !== null) {
            $this->line('  '.str($error)->limit(240));
        }

        $this->assert($error !== null, 'UPDATE on journals rejected', 'UPDATE SUCCEEDED — the ledger is mutable');

        // Layer 2: prove the trigger fires independently, by attempting the
        // update as the OWNER, which does hold UPDATE on the table.
        $triggerError = null;

        try {
            DB::connection('mysql_owner')->statement("UPDATE `{$db}`.`journals` SET memo = 'edited' WHERE id = 1");
        } catch (Throwable $e) {
            $triggerError = $e->getMessage();
        }

        if ($triggerError !== null) {
            $this->line('  '.str($triggerError)->limit(240));
        }

        $this->assert(
            $triggerError !== null && str_contains($triggerError, 'append-only'),
            'LAYER 2 — trigger rejects UPDATE even for a privileged user',
            'the schema owner was able to edit a posted journal',
        );

        // And DELETE, same two layers.
        $deleteError = null;

        try {
            DB::connection('mysql_owner')->statement("DELETE FROM `{$db}`.`journals` WHERE id = 1");
        } catch (Throwable $e) {
            $deleteError = $e->getMessage();
        }

        $this->assert(
            $deleteError !== null && str_contains($deleteError, 'append-only'),
            'LAYER 2 — trigger rejects DELETE even for a privileged user',
            'a posted journal was deleted',
        );

        $this->line('  append-only tables: '.implode(', ', ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));
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
        $this->line("  <fg=red>FAIL</> {$proves} — {$failureDetail}");
    }
}
