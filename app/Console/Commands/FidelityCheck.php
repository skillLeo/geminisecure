<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

/**
 * Pixel-diffs each built screen against the wireframe it came from.
 *
 * This replaces describing what was built. A screen is not done because it
 * looks right in a screenshot; it is done when the diff against the board is
 * under the threshold, measured, every time.
 *
 * The command owns the screen-to-route mapping because only PHP can resolve
 * the record ids a detail route needs. It writes those resolved URLs to
 * _design/SCREEN_TARGETS.json and hands the actual comparison to Playwright.
 */
class FidelityCheck extends Command
{
    protected $signature = 'fidelity:check
        {screen? : One screen id, e.g. super-admin-04. Omit to check every mapped screen.}
        {--threshold=2.0 : Maximum percentage of differing pixels before a screen fails.}
        {--url=http://127.0.0.1:8000 : Where the application is served.}';

    protected $description = 'Pixel-diff built screens against their approved wireframes';

    /**
     * Screen id => the route that reproduces it.
     *
     * 'params' names a resolver below rather than a literal id, because a
     * hardcoded id becomes wrong the first time anyone reseeds.
     *
     * @var array<string, array{route: string, params?: string, guest?: bool}>
     */
    private const MAPPING = [
        'super-admin-01' => ['route' => 'login', 'guest' => true],
        'super-admin-02' => ['route' => 'gemini.dashboard'],
        'super-admin-04' => ['route' => 'gemini.clients'],
        'super-admin-05' => ['route' => 'gemini.clients.show', 'params' => 'tenant'],
        'super-admin-14' => ['route' => 'gemini.dispatch'],
        'super-admin-17' => ['route' => 'gemini.dispatch.alert', 'params' => 'alert'],
        'super-admin-18' => ['route' => 'gemini.guard_workforce'],
        'super-admin-19' => ['route' => 'gemini.guard_workforce.show', 'params' => 'guard'],
        'super-admin-20' => ['route' => 'gemini.guard_workforce.compliance'],
        'super-admin-28' => ['route' => 'gemini.payroll_accounting'],
        'super-admin-29' => ['route' => 'gemini.payroll_accounting.show', 'params' => 'run'],
        'super-admin-32' => ['route' => 'gemini.billing_subscriptions'],
        'super-admin-36' => ['route' => 'gemini.cross_tenant_reports'],
        'super-admin-41' => ['route' => 'gemini.access_audit_log'],
        'super-admin-45' => ['route' => 'gemini.platform_settings'],
    ];

    public function handle(): int
    {
        $regionsPath = base_path('_design/SCREEN_REGIONS.json');

        if (! File::exists($regionsPath)) {
            $this->error('_design/SCREEN_REGIONS.json is missing. Run: node tests/Fidelity/build-regions.mjs');

            return self::FAILURE;
        }

        /** @var array{screens: list<array<string, mixed>>} $regions */
        $regions = json_decode(File::get($regionsPath), true, 512, JSON_THROW_ON_ERROR);

        /*
         * Generate URLs on the same host the harness browses.
         *
         * Without this, route() builds from APP_URL (localhost) while the
         * browser signs in against --url (127.0.0.1). Those are different
         * origins to a cookie jar, so every authenticated screen quietly
         * redirects to /login and diffs the sign-in page against its board.
         */
        URL::forceRootUrl($this->option('url'));

        $only = $this->argument('screen');
        $targets = [];
        $skipped = [];

        foreach ($regions['screens'] as $screen) {
            $id = $screen['id'];

            if ($only !== null && $id !== $only) {
                continue;
            }

            if (! isset(self::MAPPING[$id])) {
                $skipped[] = $id;

                continue;
            }

            $url = $this->urlFor(self::MAPPING[$id]);

            if ($url === null) {
                $this->warn("{$id}: no seed record for this route, skipping.");

                continue;
            }

            $targets[] = [
                'id' => $id,
                'title' => $screen['title'],
                'source' => $screen['source'],
                'selector' => $screen['selector'],
                'index' => $screen['index'],
                'viewport' => $screen['viewport'],
                'url' => $url,
                'guest' => self::MAPPING[$id]['guest'] ?? false,
            ];
        }

        if ($targets === []) {
            $this->error($only !== null
                ? "Screen '{$only}' is not mapped to a route yet."
                : 'No screens are mapped to routes yet.');

            return self::FAILURE;
        }

        File::put(
            base_path('_design/SCREEN_TARGETS.json'),
            json_encode([
                'note' => 'Generated by php artisan fidelity:check. Resolved URLs for the pixel harness.',
                'app_url' => $this->option('url'),
                'threshold_percent' => (float) $this->option('threshold'),
                'targets' => $targets,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
        );

        $this->line(sprintf(
            '%d screen(s) mapped, %d of 165 still unmapped.',
            count($targets),
            count($regions['screens']) - count(self::MAPPING)
        ));
        $this->newLine();

        $process = new Process(['node', 'tests/Fidelity/compare.mjs'], base_path(), null, null, 900.0);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{route: string, params?: string, guest?: bool}  $mapping
     */
    private function urlFor(array $mapping): ?string
    {
        if (! Route::has($mapping['route'])) {
            return null;
        }

        if (! isset($mapping['params'])) {
            return route($mapping['route']);
        }

        $id = match ($mapping['params']) {
            'tenant' => Tenant::estates()->value('id'),
            'guard' => Guard::query()->value('id'),
            'alert' => DuressAlert::query()->value('id'),
            'run' => PayrollRun::query()->value('id'),
            default => null,
        };

        return $id === null ? null : route($mapping['route'], [$mapping['params'] => $id]);
    }
}
