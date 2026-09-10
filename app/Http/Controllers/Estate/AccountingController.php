<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Ledger;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Accounting — board screens community-admin-25 to 28.
 *
 * All four are built. The subnav links them.
 *
 * EVERY FIGURE HERE IS THE LEDGER READ BACK. No balance is stored on an
 * account, so there is nothing that could drift from the entries it is made of
 * — see the double-entry migration for why that is structural rather than a
 * convention.
 */
class AccountingController extends Controller
{
    /**
     * The one control on this screen that is still inert.
     *
     * Adding an account changes what every future entry can post against, and
     * an account added by mistake cannot simply be removed once anything has
     * been posted to it — it can only be archived. That wants a review step
     * before it wants a button.
     */
    private const NO_ADD_ACCOUNT_YET = 'Not built yet — adding an account changes what every future entry can post against, and an account with history can only be archived, never removed. It needs a review step first.';

    /** Chart of accounts — board community-admin-25. */
    public function chart(Request $request, Ledger $ledger): Response
    {
        return inertia('Estate/Accounting/ChartOfAccounts', [
            'estate' => ['name' => (string) tenant()->name],
            ...$ledger->chartOfAccounts(),

            /*
             * The other three Accounting screens now exist, so the subnav links
             * them. They were disabled here with "not built yet" reasons while
             * they were being built; leaving those in place would have left a
             * console telling an accountant three screens are missing while
             * they sit one click away.
             */
            'tabs' => [
                ['key' => 'chart', 'label' => 'Chart of accounts', 'route' => 'estate.accounting.chart'],
                ['key' => 'vendors', 'label' => 'Vendors', 'route' => 'estate.accounting.vendors'],
                ['key' => 'bills', 'label' => 'Bills & payments', 'route' => 'estate.accounting.bills'],
                ['key' => 'reconciliation', 'label' => 'Bank reconciliation', 'route' => 'estate.accounting.reconciliation'],
            ],
            'reasons' => ['add' => self::NO_ADD_ACCOUNT_YET],
        ]);
    }
}
