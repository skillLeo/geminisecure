<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-applies append-only grants across every estate.
 *
 *   php artisan grants:estates
 *
 * ApplyAppendOnlyGrants runs once, at provisioning. A migration that adds a
 * table afterwards leaves that table with NO update or delete grant for the
 * estate's user, because those privileges are withheld at database level and
 * granted back per table (D-017). The symptom is subtle: reads work, inserts
 * work, and only an update fails - possibly weeks later.
 *
 * So this must run after every `tenants:migrate`. It is idempotent.
 */
class ApplyEstateGrants extends Command
{
    protected $signature = 'grants:estates';

    protected $description = 'Re-apply per-table append-only grants to every estate database';

    public function handle(): int
    {
        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->warn('No estates provisioned.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($estates as $estate) {
            try {
                (new ApplyAppendOnlyGrants($estate))->handle();
                $this->line("  <fg=green>OK</> {$estate->getTenantKey()} ({$estate->database()->getName()})");
            } catch (Throwable $e) {
                $failed++;
                $this->line("  <fg=red>FAIL</> {$estate->getTenantKey()}: ".$e->getMessage());
            }
        }

        $this->info('Append-only tables: '.implode(', ', ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
