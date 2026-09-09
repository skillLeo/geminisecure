<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The cross-tenant report catalogue — Super Admin screen 36.
 *
 * TWO RULES GOVERN EVERY REPORT LISTED HERE, and both are structural rather
 * than conventions to remember:
 *
 * 1. A cross-tenant report reads ONLY from gs_platform. Nothing here may open
 *    an estate database. If a figure cannot be produced from central data it is
 *    not a cross-tenant report, and the honest answer is to say so on the card
 *    rather than to fan out across every estate and call the result an
 *    aggregate. That is why Client Health carries the note it does.
 *
 * 2. Every figure is an AGGREGATE. None can be drilled down to an individual
 *    resident. A per-estate contribution to MRR is a property of the
 *    subscription, not of anybody living there, which is precisely why it can
 *    be computed without touching a resident row.
 *
 * Note what is therefore absent and cannot be added: arrears totals, collection
 * rates, anything derived from resident charges. Those live in estate databases
 * and reaching them would break both rules at once.
 *
 * The catalogue is a launcher, so the only question this class answers is
 * whether each report can be OPENED. That is asked of the router and the
 * permission table rather than recorded as a hand-maintained flag: when a
 * report's screen is built and routed, its card lights up on its own, and a
 * viewer whose role does not reach the module the report lives in is told so
 * instead of being sent to a 403.
 */
class CrossTenantReports
{
    /**
     * The catalogue, in the two groups the board draws.
     *
     * @return list<array{name: string, reports: list<array{
     *     key: string,
     *     icon: string,
     *     name: string,
     *     description: string,
     *     href: string|null,
     *     unavailable: string|null,
     * }>}>
     */
    public function catalogue(User $viewer): array
    {
        $groups = [];

        foreach ($this->definitions() as $group) {
            $reports = [];

            foreach ($group['reports'] as $report) {
                $href = null;
                $unavailable = null;

                if (! Route::has($report['route'])) {
                    $unavailable = $report['pending'];
                } elseif (! $viewer->can($report['permission'])) {
                    $unavailable = 'Your role does not include '.$report['module'].', where this report is kept';
                } else {
                    $href = route($report['route']);
                }

                $reports[] = [
                    'key' => $report['key'],
                    'icon' => $report['icon'],
                    'name' => $report['name'],
                    'description' => $report['description'],
                    'href' => $href,
                    'unavailable' => $unavailable,
                ];
            }

            $groups[] = [
                'name' => $group['name'],
                'reports' => $reports,
            ];
        }

        return $groups;
    }

    /**
     * Every report the platform offers, whether or not it can be opened yet.
     *
     * `route` is where the report lives once it exists; `permission` is the
     * gate that route is behind, named <console>.<module>.<verb> like every
     * other. `pending` is what the card says while the screen is not built —
     * shown in a title on a disabled control, never as a silent dead click.
     *
     * The names and descriptions are the board's own labels, verbatim.
     *
     * @return list<array{name: string, reports: list<array{
     *     key: string,
     *     icon: string,
     *     name: string,
     *     description: string,
     *     route: string,
     *     permission: string,
     *     module: string,
     *     pending: string,
     * }>}>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'Revenue',
                'reports' => [
                    [
                        'key' => 'mrr_trend',
                        'icon' => 'reports',
                        'name' => 'MRR Trend',
                        'description' => 'Platform MRR over time, annotated with client events',
                        'route' => 'gemini.cross_tenant_reports.mrr_trend',
                        'permission' => 'gemini.cross_tenant_reports.view',
                        'module' => 'cross-tenant reports',
                        'pending' => 'Available when the MRR trend report ships',
                    ],
                    [
                        'key' => 'revenue_by_tier',
                        'icon' => 'currency',
                        'name' => 'Revenue by Tier',
                        'description' => 'Essential vs Standard vs Premium, current split',
                        'route' => 'gemini.cross_tenant_reports.revenue_by_tier',
                        'permission' => 'gemini.cross_tenant_reports.view',
                        'module' => 'cross-tenant reports',
                        'pending' => 'Available when the revenue by tier report ships',
                    ],
                    [
                        'key' => 'churn_retention',
                        'icon' => 'alert',
                        'name' => 'Churn & Retention',
                        'description' => 'Client retention rate and any cancelled subscriptions',
                        'route' => 'gemini.cross_tenant_reports.churn_retention',
                        'permission' => 'gemini.cross_tenant_reports.view',
                        'module' => 'cross-tenant reports',
                        'pending' => 'Available when the churn and retention report ships',
                    ],
                ],
            ],
            [
                'name' => 'Operational',
                'reports' => [
                    [
                        'key' => 'guard_utilisation',
                        'icon' => 'guards',
                        'name' => 'Guard Utilization',
                        'description' => 'Guards deployed vs contracted, across every client',
                        'route' => 'gemini.cross_tenant_reports.guard_utilisation',
                        'permission' => 'gemini.cross_tenant_reports.view',
                        'module' => 'cross-tenant reports',
                        'pending' => 'Available when the guard utilization report ships',
                    ],
                    /*
                     * The one report that is already built. Licence status
                     * across the whole workforce is exactly the guard
                     * compliance screen, and every guard row is central, so
                     * this card opens the real thing rather than a second
                     * implementation of the same query.
                     */
                    [
                        'key' => 'psra_compliance',
                        'icon' => 'shield',
                        'name' => 'PSRA Compliance Summary',
                        'description' => 'Licence status across the whole guard workforce',
                        'route' => 'gemini.guard_workforce.compliance',
                        'permission' => 'gemini.guard_workforce.view',
                        'module' => 'the guard workforce',
                        'pending' => 'Available when the PSRA compliance report ships',
                    ],
                    [
                        'key' => 'client_health',
                        'icon' => 'clients',
                        'name' => 'Client Health',
                        'description' => 'Feature adoption and engagement, by client',
                        'route' => 'gemini.cross_tenant_reports.client_health',
                        'permission' => 'gemini.cross_tenant_reports.view',
                        'module' => 'cross-tenant reports',
                        /*
                         * Stated on the card, because it constrains what this
                         * report may ever be rather than merely when it lands.
                         * Adoption is recorded inside each estate's own
                         * database; a console that never opens one can only
                         * report it once the estates roll it up into central
                         * data themselves.
                         */
                        'pending' => 'Available when the client health report ships — adoption must first be rolled up into platform data, because this console never reads an estate database',
                    ],
                ],
            ],
        ];
    }
}
