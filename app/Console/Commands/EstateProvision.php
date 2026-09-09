<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\EstateProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan estate:provision phoenixpark "Phoenix Park"
 */
class EstateProvision extends Command
{
    protected $signature = 'estate:provision
        {subdomain : becomes the tenant id and the gs_estate_ database suffix}
        {name : display name, e.g. "Phoenix Park"}
        {--status=onboarding}';

    protected $description = 'Provision an estate: database, dedicated MySQL user, migrations, append-only grants';

    public function handle(EstateProvisioner $provisioner): int
    {
        $subdomain = (string) $this->argument('subdomain');

        try {
            $tenant = $provisioner->provision(
                $subdomain,
                (string) $this->argument('name'),
                (string) $this->option('status'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Provisioned {$tenant->name}");
        $this->table(['Property', 'Value'], [
            ['tenant id / subdomain', $tenant->getTenantKey()],
            ['database', $tenant->database()->getName()],
            ['database user', $tenant->database()->getUsername()],
            ['domain', $tenant->domains()->first()->domain ?? '—'],
            ['status', $tenant->status],
        ]);

        return self::SUCCESS;
    }
}
