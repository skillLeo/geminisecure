<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Dues & ledger — board screens community-admin-05, 06 and 35.
 *
 * EVERY FIGURE ON THESE SCREENS COMES FROM POSTED JOURNAL LINES. Not one is
 * read from `charges` or `payments`: those tables hold the billing — a due
 * date, a receipt number, a method — and the ledger holds the money. A balance
 * summed from the billing records would agree with the accounts by coincidence,
 * and coincidence is what fails at an audit. `EstateArrearsTest` re-sums every
 * total below in raw SQL to prove it.
 *
 * POSTING A CHARGE NEEDS `create`, NOT `view`. A committee member may read the
 * arrears all day and may not add to what a household owes; the Property
 * Manager holds neither, by platform invariant rather than estate preference.
 */
class DuesController extends Controller
{
    /**
     * Why the collection actions are inert.
     *
     * Each is a real decision with a consequence outside this screen — a
     * restriction reaches a guard at a gate, a dunning notice reaches a
     * resident's phone — and each has its own board still to be built.
     */
    private const NO_PLAN_YET = 'Not built yet — a payment plan is an agreement with a household and needs its own screen, which is board 7.';

    private const NO_DUNNING_YET = 'Not built yet — a dunning notice is sent to a resident and logged verbatim so a dispute can be settled from the record. Board 8.';

    private const NO_RESTRICT_YET = 'Not built yet — restriction stops guest passes at a gate. It is never applied from a list screen without the household in front of you.';

    private const NO_EXPORT_YET = 'Not built yet — an arrears export leaves the estate as a file naming who owes what, and needs a retention rule before it needs a button.';

    /** Arrears command centre — board community-admin-05. */
    public function arrears(Request $request, Dues $dues): Response
    {
        return inertia('Estate/Dues/Arrears', [
            'estate' => ['name' => (string) tenant()->name],
            ...$dues->arrearsBoard($request->string('phase')->toString(), $request->boolean('overdue')),
            'canCharge' => $request->user()->can('estate.dues_ledger.create'),
            'reasons' => [
                'plan' => self::NO_PLAN_YET,
                'dunning' => self::NO_DUNNING_YET,
                'restrict' => self::NO_RESTRICT_YET,
                'export' => self::NO_EXPORT_YET,
            ],
        ]);
    }

    /** One unit's ledger — board community-admin-06. */
    public function unit(Request $request, Unit $unit, Dues $dues): Response
    {
        return inertia('Estate/Dues/UnitLedger', [
            'estate' => ['name' => (string) tenant()->name],
            ...$dues->unitBoard($unit),
            'canRecord' => $request->user()->can('estate.payments.create'),
            'reasons' => [
                'plan' => self::NO_PLAN_YET,
                'dunning' => self::NO_DUNNING_YET,
                'hardship' => 'Not built yet — a hardship flag changes what the collection process may do to a household, and needs a recorded committee decision behind it.',
                'statement' => 'Not built yet — a printed statement is a document a resident keeps, and needs a template and a retention rule.',
            ],
        ]);
    }

    /** Post a charge — board community-admin-35. */
    public function newCharge(Request $request, Dues $dues): Response
    {
        $unit = $request->filled('unit')
            ? Unit::where('reference', $request->string('unit')->toString())->first()
            : Unit::query()->orderBy('id')->first();

        return inertia('Estate/Dues/NewCharge', [
            'estate' => ['name' => (string) tenant()->name],
            'unit' => $unit === null ? null : $dues->unitHeader($unit),
            'currentBalanceMinor' => $unit === null ? 0 : $dues->balanceOf($unit)->getMinorAmount()->toInt(),
            'accounts' => Account::query()
                ->where('type', Account::INCOME)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(fn (Account $a): array => ['code' => $a->code, 'label' => $a->code.' — '.$a->name])
                ->all(),
            'types' => [
                ['value' => 'dues', 'label' => 'Maintenance fee'],
                ['value' => 'special_assessment', 'label' => 'Special assessment'],
                ['value' => 'fine', 'label' => 'Fine'],
                ['value' => 'amenity', 'label' => 'Amenity fee'],
            ],
            'canPost' => $request->user()->can('estate.dues_ledger.create'),
            'blockedReason' => 'Posting a charge adds to what a household owes and needs Dues & ledger create access. You are able to read this screen.',
            'bulkReason' => 'Not built yet — a charge posted to a whole phase or the whole estate raises hundreds of entries at once, and needs a preview and a confirmation step before it needs a button.',
        ]);
    }

    /** Post it. A real state change, and a journal entry. */
    public function postCharge(Request $request, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'string'],
            'type' => ['required', 'string', 'in:dues,special_assessment,fine,amenity'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date'],
            'account' => ['required', 'string'],
        ]);

        $unit = Unit::where('reference', $data['unit'])->firstOrFail();

        try {
            $dues->charge(
                unit: $unit,
                /*
                 * Constructed from a decimal string, never a float. `Money::of`
                 * refuses a value it cannot represent exactly, which is the
                 * whole reason the amount arrives as a string and not a
                 * cast-to-float.
                 */
                amount: Money::of((string) $data['amount'], 'JMD'),
                description: $data['description'],
                dueOn: $data['due_on'],
                type: $data['type'],
                account: $data['account'],
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', 'Charge posted to '.$unit->reference.'.');
    }

    /**
     * Where a unit's ledger lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function unitPath(Unit $unit): string
    {
        $path = '/finance/units/'.$unit->id;

        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
