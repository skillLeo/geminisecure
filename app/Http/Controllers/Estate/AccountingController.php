<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Services\Estate\Ledger;
use DomainException;
use Illuminate\Http\RedirectResponse;
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
 *
 * CHANGING THE CHART IS `configure`, NOT `create` (12 §2, Wave 1). Adding an
 * account changes what every future entry can post against, and an account
 * with history can only be archived, never removed — so it is the verb the
 * matrix gives to Full alone, and the screen carries a review step before the
 * press. Nothing here deletes.
 */
class AccountingController extends Controller
{
    private const NO_CONFIGURE_ACCESS = 'Adding to or archiving from the chart changes what every future entry may post against, and needs Accounting configure access — Full, not Entry. You are able to read this screen.';

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
            'canConfigure' => $request->user()->can('estate.accounting_posting.configure'),
            'blockedReason' => self::NO_CONFIGURE_ACCESS,

            // What a new account may be filed under: every active account, by
            // type, so the form can offer only parents of the type chosen.
            'parents' => Account::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])
                ->map(static fn (Account $account): array => [
                    'id' => $account->id,
                    'type' => $account->type,
                    'label' => $account->code.' — '.$account->name,
                ])
                ->all(),
        ]);
    }

    /** Add an account — after the screen's review step. */
    public function addAccount(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:asset,liability,equity,income,expense'],
            'parent_id' => ['nullable', 'integer'],
            'is_bank_account' => ['nullable', 'boolean'],

            // THE REVIEW STEP, enforced server-side: the person adding it has
            // read the code, the name and the type back and confirmed them.
            'reviewed' => ['accepted'],
        ]);

        $parent = isset($data['parent_id']) ? Account::query()->find($data['parent_id']) : null;

        if (isset($data['parent_id']) && $parent === null) {
            return back()->withErrors(['parent_id' => 'That parent account is not in this chart.'])->withInput();
        }

        try {
            $account = $ledger->addAccount(
                code: $data['code'],
                name: $data['name'],
                type: $data['type'],
                parent: $parent,
                isBankAccount: $request->boolean('is_bank_account'),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['code' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/chart-of-accounts'))
            ->with('success', $account->code.' '.$account->name.' added to the chart. Every future entry may post to it; it can be archived and never removed.');
    }

    /** Archive an account. Never delete. */
    public function archiveAccount(Request $request, Account $account, Ledger $ledger): RedirectResponse
    {
        try {
            $ledger->archiveAccount($account);
        } catch (DomainException $refused) {
            return back()->withErrors(['account' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/chart-of-accounts'))
            ->with('success', $account->code.' '.$account->name.' archived. Nothing already posted to it has moved; nothing new may post to it.');
    }

    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
