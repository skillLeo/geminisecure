<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Invoice;
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
     * 'depicts' pins a detail screen to the RECORD ITS BOARD DRAWS. The boards
     * say which one in their own titles — "Client Detail — Phoenix Park
     * Village 1" is a different screen from "Client Detail — Ocean View
     * Gardens", and they are numbered separately. Opening whichever record
     * happens to sort first diffs one estate's data against another's and
     * reports it as a styling fault.
     *
     * @var array<string, array{route: string, params?: string, depicts?: string, guest?: bool}>
     */
    private const MAPPING = [
        // --- auth ------------------------------------------------------
        'super-admin-01' => ['route' => 'login', 'guest' => true],

        // --- dashboard -------------------------------------------------
        'super-admin-02' => ['route' => 'gemini.dashboard'],
        'super-admin-03' => ['route' => 'gemini.dashboard.activity'],

        // --- clients ---------------------------------------------------
        'super-admin-04' => ['route' => 'gemini.clients'],
        'super-admin-05' => ['route' => 'gemini.clients.show', 'params' => 'tenant', 'depicts' => 'phoenixpark'],
        // "Ocean View Gardens — activate plan". The board names an ONBOARDING
        // client, not a live one: activating a plan is what happens at the end
        // of onboarding, so the screen is drawn on the estate that is having it
        // done to them.
        'super-admin-06' => ['route' => 'gemini.clients.plan', 'params' => 'tenant', 'depicts' => 'oceanview'],
        'super-admin-08' => ['route' => 'gemini.clients.create'],
        'super-admin-09' => ['route' => 'gemini.clients.show', 'params' => 'tenant', 'depicts' => 'oceanview'],
        'super-admin-10' => ['route' => 'gemini.clients.guards', 'params' => 'tenant', 'depicts' => 'phoenixpark'],
        'super-admin-11' => ['route' => 'gemini.clients.message', 'params' => 'tenant', 'depicts' => 'phoenixpark'],

        // --- dispatch --------------------------------------------------
        'super-admin-12' => ['route' => 'gemini.dispatch.map'],
        'super-admin-13' => ['route' => 'gemini.dispatch.coverage'],
        'super-admin-14' => ['route' => 'gemini.dispatch'],
        'super-admin-15' => ['route' => 'gemini.dispatch.alertness'],
        'super-admin-16' => ['route' => 'gemini.dispatch.requests'],
        'super-admin-17' => ['route' => 'gemini.dispatch.alert', 'params' => 'alert'],

        // --- guard workforce -------------------------------------------
        'super-admin-18' => ['route' => 'gemini.guard_workforce'],
        'super-admin-19' => ['route' => 'gemini.guard_workforce.show', 'params' => 'guard', 'depicts' => 'GS-1041'],
        'super-admin-20' => ['route' => 'gemini.guard_workforce.compliance'],
        'super-admin-21' => ['route' => 'gemini.guard_workforce.compliance_action', 'params' => 'guard', 'depicts' => 'GS-1049'],
        'super-admin-22' => ['route' => 'gemini.guard_workforce.create'],
        'super-admin-23' => ['route' => 'gemini.guard_workforce.show', 'params' => 'guard', 'depicts' => 'GS-1049'],

        // --- security operations ---------------------------------------
        'super-admin-24' => ['route' => 'gemini.guard_workforce.roster'],
        'super-admin-25' => ['route' => 'gemini.guard_workforce.standing_orders'],
        'super-admin-26' => ['route' => 'gemini.guard_workforce.gate_activity'],
        'super-admin-27' => ['route' => 'gemini.guard_workforce.incidents'],

        // --- payroll ---------------------------------------------------
        'super-admin-28' => ['route' => 'gemini.payroll_accounting'],
        'super-admin-29' => ['route' => 'gemini.payroll_accounting.show', 'params' => 'run', 'depicts' => 'GS-PR-202609-2'],
        'super-admin-30' => ['route' => 'gemini.payroll_accounting.filings'],
        'super-admin-31' => ['route' => 'gemini.payroll_accounting.rates'],

        // --- billing ---------------------------------------------------
        'super-admin-32' => ['route' => 'gemini.billing_subscriptions'],
        // Board title: "Invoice — Phoenix Park, Aug 2026".
        'super-admin-33' => ['route' => 'gemini.billing_subscriptions.invoice', 'params' => 'invoice', 'depicts' => 'PH-INV-202608'],
        'super-admin-34' => ['route' => 'gemini.billing_subscriptions.plans'],
        'super-admin-35' => ['route' => 'gemini.billing_subscriptions.payment_methods'],

        // --- cross-tenant reports --------------------------------------
        'super-admin-07' => ['route' => 'gemini.cross_tenant_reports.client_health'],
        'super-admin-36' => ['route' => 'gemini.cross_tenant_reports'],
        'super-admin-37' => ['route' => 'gemini.cross_tenant_reports.mrr'],
        'super-admin-38' => ['route' => 'gemini.cross_tenant_reports.revenue'],
        'super-admin-39' => ['route' => 'gemini.cross_tenant_reports.churn'],
        'super-admin-40' => ['route' => 'gemini.cross_tenant_reports.utilisation'],

        // --- audit -----------------------------------------------------
        'super-admin-41' => ['route' => 'gemini.access_audit_log'],

        // --- platform settings -----------------------------------------
        /*
         * Measured again as of the settings rename. This row was UNVERIFIED
         * while `gemini.platform_settings` served /settings/roles, because it
         * was then comparing board 42 against screen 45 — two boards sharing a
         * shell, producing a believable percentage that measured nothing.
         * `gemini.platform_settings` is now the module's own landing screen.
         */
        'super-admin-42' => ['route' => 'gemini.platform_settings'],
        'super-admin-43' => ['route' => 'gemini.platform_settings.packages'],
        'super-admin-44' => ['route' => 'gemini.platform_settings.line_items'],
        'super-admin-45' => ['route' => 'gemini.platform_settings.roles'],
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
                'unverified' => self::unverifiedReason($id),
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
     * @param  array{route: string, params?: string, depicts?: string, guest?: bool}  $mapping
     */
    private function urlFor(array $mapping): ?string
    {
        if (! Route::has($mapping['route'])) {
            return null;
        }

        if (! isset($mapping['params'])) {
            return route($mapping['route']);
        }

        /*
         * A detail board depicts one record, named in its own title. Resolve
         * that record by a stable business key — a subdomain, an employee
         * number — rather than an auto-increment id, which changes the first
         * time anyone reseeds.
         */
        $id = isset($mapping['depicts'])
            ? $this->depicted($mapping['params'], $mapping['depicts'])
            : match ($mapping['params']) {
                'tenant' => Tenant::estates()->value('id'),
                'guard' => Guard::query()->value('id'),
                'alert' => DuressAlert::query()->value('id'),
                'run' => PayrollRun::query()->value('id'),
                'invoice' => Invoice::query()->value('id'),
                default => null,
            };

        return $id === null ? null : route($mapping['route'], [$mapping['params'] => $id]);
    }

    /**
     * Why a screen's number should not be read as a verdict.
     *
     * A board built out of order can end up measured against a sibling that
     * shares its shell — the route it will eventually own does not exist yet,
     * so the name resolves to a neighbour. That produces a plausible
     * percentage which measures nothing, and a plausible percentage is worse
     * than none: it reads as a pass. Listed here, such a row prints
     * "UNVERIFIED (reason)" with no figure, writes no diff images, and counts
     * in neither the numerator nor the denominator.
     *
     * super-admin-42 sat here until the platform settings rename gave it its
     * own route. Empty now, and kept because the next screen built ahead of
     * its route will need it.
     */
    private static function unverifiedReason(string $screenId): ?string
    {
        /** @var array<string, string> $unverified */
        $unverified = [];

        return $unverified[$screenId] ?? null;
    }

    /**
     * The record a board draws, found by its business key.
     *
     * A tenant IS its subdomain, so that key is the id. A guard is found by
     * employee number and an invoice by reference, because both of those are
     * printed on the board itself and neither moves when the database is
     * rebuilt.
     */
    private function depicted(string $param, string $key): int|string|null
    {
        return match ($param) {
            'tenant' => Tenant::find($key)?->getTenantKey(),
            'guard' => Guard::query()->where('employee_number', $key)->value('id'),
            'invoice' => Invoice::query()->where('reference', $key)->value('id'),
            // `period` is an accessor, not a column — the stored key is the run
            // reference. Querying the accessor silently found nothing and fell
            // through to "no seed record", which reads as missing data rather
            // than a wrong lookup.
            'run' => PayrollRun::query()->where('reference', $key)->value('id'),
            default => null,
        };
    }
}
