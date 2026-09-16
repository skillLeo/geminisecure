<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\BallotReceipt;
use App\Services\Estate\Governance;
use App\Services\ResidentApp\ResidentAccounts;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Elections from the household's side: the paper, eligibility, the vote, the result (13 D3).
 * Boards resident-app-19, -20, -21.
 *
 * THE SECRET BALLOT HOLDS ON THIS PATH TOO. The vote goes through
 * `Governance::castVote`, which writes a receipt (THAT the household voted) and
 * the marks (WHAT was chosen) with no column that pairs them. This controller
 * adds nothing that could: the response names the ballot and the day and no
 * mark, option or receipt id, and the route's idempotency record is kept
 * `opaque` — its request hash covers the method and path only, never the body,
 * because a hash of three option ids stored beside an account id is the choice,
 * recoverable by trying every combination on the paper.
 * `BallotUnlinkabilityTest` holds the API path to that.
 */
class ElectionsController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly Governance $governance,
    ) {}

    public function index(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        $voted = BallotReceipt::query()->where('unit_id', $unit->id)->pluck('ballot_id')->all();

        $items = Ballot::query()->with(['positions.options', 'options'])
            ->where('stage', '!=', Ballot::DRAFT)
            ->orderByDesc('year')->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Ballot $b): array => [
                ...$this->ballotHeader($b),
                'voting_open' => $b->isOpenForVoting(),
                'has_voted' => in_array($b->id, $voted, true),
                'results_published' => $b->published_at !== null,
                'positions' => $b->positions->sortBy('sort_order')->map(static fn (BallotPosition $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'seat_count' => $p->seat_count,
                    'options' => $p->options->sortBy('sort_order')->map(static fn (BallotOption $o): array => ['id' => $o->id, 'label' => $o->label])->values()->all(),
                ])->values()->all(),
                'options' => $b->options->whereNull('ballot_position_id')->sortBy('sort_order')->map(static fn (BallotOption $o): array => ['id' => $o->id, 'label' => $o->label])->values()->all(),
            ])->all();

        return response()->json(['items' => $items]);
    }

    public function eligibility(int $ballot, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $record = $this->visible($ballot);
        $check = $this->governance->eligibility($unit);

        return response()->json([
            'ballot_id' => $record->id,
            'eligible' => $check['eligible'],
            'reason' => $check['reason'],
            'checked_on' => $check['checked_on'],
            'voting_open' => $record->isOpenForVoting(),
            'has_voted' => $this->governance->hasVoted($record, $unit),
        ]);
    }

    public function cast(Request $request, int $ballot, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $record = $this->visible($ballot);

        $data = $request->validate([
            'option_ids' => ['required', 'array', 'min:1', 'max:50'],
            'option_ids.*' => ['integer', 'distinct'],
        ]);

        if (! $record->isOpenForVoting()) {
            throw ApiError::conflict('poll_closed', 'Voting on '.$record->title.' is not open.');
        }

        if ($this->governance->hasVoted($record, $unit)) {
            throw ApiError::conflict('already_voted', 'Your household has already voted in '.$record->title.'. A vote cast cannot be shown, changed or taken back.');
        }

        $check = $this->governance->eligibility($unit);

        if (! $check['eligible']) {
            throw ApiError::forbidden('not_eligible', 'Your household is not eligible to vote in this election ('.$check['reason'].'). Contact the estate office.');
        }

        try {
            $this->governance->castVote($record, $unit, array_map('intval', $data['option_ids']));
        } catch (DomainException $refused) {
            throw $this->governance->hasVoted($record, $unit)
                ? ApiError::conflict('already_voted', 'Your household has already voted in '.$record->title.'.')
                : ApiError::unprocessable('paper_invalid', $refused->getMessage());
        }

        return response()->json([
            'ballot_id' => $record->id,
            'voted' => true,
            'voted_on' => Carbon::today()->toDateString(),
        ], 201);
    }

    public function results(int $ballot, DeviceContext $context): JsonResponse
    {
        $this->accounts->home($context);
        $record = $this->visible($ballot)->load(['positions.options', 'options']);

        if ($record->published_at === null) {
            throw ApiError::conflict('results_not_published', 'The results of '.$record->title.' have not been published yet.');
        }

        $tally = $this->governance->tally($record);
        $turnout = $this->governance->turnout($record);
        $quorum = $this->governance->quorum($record);
        $option = static fn (BallotOption $o): array => ['id' => $o->id, 'label' => $o->label, 'votes' => $tally[$o->id] ?? 0];

        return response()->json([
            ...$this->ballotHeader($record),
            'certified_at' => $record->certified_at?->toIso8601String(),
            'published_at' => $record->published_at->toIso8601String(),
            'outcome_statement' => $record->outcome_statement,
            'turnout' => ['cast' => $turnout['cast'], 'eligible' => $turnout['eligible'], 'percent' => $turnout['percent']],
            'quorum' => ['required' => $quorum['required'], 'met' => $quorum['met']],
            'positions' => $record->positions->sortBy('sort_order')->map(static fn (BallotPosition $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'seat_count' => $p->seat_count,
                'options' => $p->options->sortBy('sort_order')->map($option)->values()->all(),
            ])->values()->all(),
            'options' => $record->options->whereNull('ballot_position_id')->sortBy('sort_order')->map($option)->values()->all(),
        ]);
    }

    private function visible(int $id): Ballot
    {
        $ballot = Ballot::query()->whereKey($id)->where('stage', '!=', Ballot::DRAFT)->first();

        if ($ballot === null) {
            throw ApiError::notFound('not_found', 'No election with that id.');
        }

        return $ballot;
    }

    /** @return array<string, mixed> */
    private function ballotHeader(Ballot $b): array
    {
        return [
            'id' => $b->id,
            'year' => $b->year,
            'title' => $b->title,
            'kind' => $b->kind,
            'question' => $b->question,
            'stage' => $b->stage,
            'stage_label' => $b->stageLabel(),
            'opens_at' => $b->opens_at?->toIso8601String(),
            'closes_at' => $b->closes_at?->toIso8601String(),
        ];
    }
}
