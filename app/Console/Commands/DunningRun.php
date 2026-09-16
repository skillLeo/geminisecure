<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\Collections;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan dunning:run                     # every live estate
 *   php artisan dunning:run --estate=phoenixpark --dry-run
 *
 * The automated arrears ladder (13 A4) — scheduled every morning in
 * `routes/console.php`. What it sends, what it skips and why is
 * `Collections::runAutomated()`; this only walks the estates.
 *
 * LIVE ESTATES ONLY. An estate still onboarding has a ledger nobody has
 * reconciled yet, and a final demand drawn from it would be the platform's
 * first message to a resident — about money that may not be owed.
 *
 * ONE ESTATE FAILING DOES NOT STOP THE REST. Each is reported and the command
 * exits non-zero at the end, so the scheduler's log says which estate failed
 * without every other estate's households going unchased that morning.
 */
class DunningRun extends Command
{
    protected $signature = 'dunning:run
        {--estate= : one estate only}
        {--dry-run : report what would be sent, and send nothing}';

    protected $description = 'Send the automated arrears reminders each live estate is due, skipping flagged households and agreed plans';

    public function handle(): int
    {
        $estates = Tenant::estates(function ($query): void {
            $query->where('status', 'active')->orderBy('id');

            if ($this->option('estate') !== null) {
                $query->whereKey((string) $this->option('estate'));
            }
        });

        if ($estates->isEmpty()) {
            $this->warn('No live estate to run.');

            return self::SUCCESS;
        }

        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($estates as $estate) {
            try {
                $report = $estate->run(fn (): array => app(Collections::class)->runAutomated(dryRun: $dryRun));
            } catch (Throwable $failure) {
                $failed++;
                $this->error("{$estate->name}: the run failed — {$failure->getMessage()}");

                continue;
            }

            $this->info(sprintf(
                '%s: %d %s, %d flagged and suppressed, %d on an agreed plan, %d not yet due, %d already sent this step.',
                $estate->name,
                count($report['sent']),
                $dryRun ? 'would be sent' : 'sent',
                $report['suppressed'],
                $report['on_plan'],
                $report['not_due'],
                $report['already_sent'],
            ));

            foreach ($report['sent'] as $sent) {
                $this->line("  {$sent['unit']} · {$sent['step']}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
