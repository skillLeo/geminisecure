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
 * The chart of accounts is built; vendors, bills and the bank reconciliation
 * are the next three and their tabs say so rather than linking into a 404.
 *
 * EVERY FIGURE HERE IS THE LEDGER READ BACK. No balance is stored on an
 * account, so there is nothing that could drift from the entries it is made of
 * — see the double-entry migration for why that is structural rather than a
 * convention.
 */
class AccountingController extends Controller
{
    private const NO_VENDORS_YET = 'Not built yet — the supplier register is board 26, and a TRN has to be on file before a bill can be paid.';

    private const NO_BILLS_YET = 'Not built yet — board 27. Recording a bill posts a journal automatically, so it needs the vendor register behind it first.';

    private const NO_RECONCILIATION_YET = 'Not built yet — board 28. A reconciliation cannot be completed while a difference remains, and that needs statement import before it needs a screen.';

    private const NO_ADD_ACCOUNT_YET = 'Not built yet — adding an account changes what every future entry can post against, and needs a review step before it needs a button.';

    /** Chart of accounts — board community-admin-25. */
    public function chart(Request $request, Ledger $ledger): Response
    {
        return inertia('Estate/Accounting/ChartOfAccounts', [
            'estate' => ['name' => (string) tenant()->name],
            ...$ledger->chartOfAccounts(),
            'reasons' => [
                'vendors' => self::NO_VENDORS_YET,
                'bills' => self::NO_BILLS_YET,
                'reconciliation' => self::NO_RECONCILIATION_YET,
                'add' => self::NO_ADD_ACCOUNT_YET,
            ],
        ]);
    }
}
