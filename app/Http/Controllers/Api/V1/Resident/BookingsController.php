<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Services\Estate\Amenities;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Amenities and the household's bookings (13 D3). Boards resident-app-22, -23.
 *
 * The same diary board 19 decides from, through `Amenities::book` and
 * `Amenities::cancel`: capacity, opening hours, the diary and the arrears rule
 * are the estate's own checks, and their refusals come back word for word.
 */
class BookingsController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly Amenities $amenities,
    ) {}

    public function amenities(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $mayBook = $this->amenities->mayBook($unit);

        return response()->json([
            'may_book' => $mayBook,
            'items' => Amenity::query()->where('is_active', true)->where('is_bookable', true)->orderBy('sort_order')->get()
                ->map(static fn (Amenity $a): array => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'icon_key' => $a->icon_key,
                    'capacity' => $a->capacity,
                    'opens_at' => $a->opens_at,
                    'closes_at' => $a->closes_at,
                    'booking_fee' => Decimal::of((int) ($a->booking_fee_minor ?? 0)),
                    'deposit' => Decimal::of((int) ($a->deposit_minor ?? 0)),
                    'currency' => $a->currency,
                    'cancellation_hours' => $a->cancellation_hours,
                    'booking_window_days' => $a->booking_window_days,
                ])->all(),
        ]);
    }

    public function index(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        return response()->json([
            'items' => AmenityBooking::query()->with('amenity')->where('unit_id', $unit->id)
                ->orderByDesc('starts_at')->limit(100)->get()
                ->map(fn (AmenityBooking $b): array => $this->shape($b))->all(),
        ]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'amenity_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'guests' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amenity = Amenity::query()->find($data['amenity_id']);

        if ($amenity === null) {
            throw ApiError::notFound('not_found', 'No amenity with that id.');
        }

        $starts = Carbon::parse($data['starts_at']);

        if ($starts->isPast()) {
            throw ApiError::unprocessable('booking_refused', 'A booking starts in the future.');
        }

        if ($amenity->booking_window_days !== null && $starts->greaterThan(Carbon::now()->addDays($amenity->booking_window_days))) {
            throw ApiError::unprocessable('booking_refused', 'The '.$amenity->name.' takes bookings up to '.$amenity->booking_window_days.' days ahead.');
        }

        try {
            $booking = $this->amenities->book(
                amenity: $amenity,
                unit: $home['unit'],
                residentName: (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident'),
                startsAt: $starts,
                endsAt: Carbon::parse($data['ends_at']),
                guests: $data['guests'] ?? null,
                notes: $data['notes'] ?? null,
                residentId: $home['resident']?->id,
            );
        } catch (DomainException $refused) {
            throw ApiError::unprocessable('booking_refused', $refused->getMessage());
        }

        $booking->forceFill(['is_simulated' => $request->boolean('simulated')])->save();

        return response()->json($this->shape($booking->load('amenity')), 201);
    }

    public function cancel(int $booking, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $record = AmenityBooking::query()->with('amenity')->whereKey($booking)->where('unit_id', $unit->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No booking of your household\'s with that id.');
        }

        try {
            $this->amenities->cancel($record);
        } catch (DomainException $refused) {
            throw ApiError::conflict('cancellation_refused', $refused->getMessage());
        }

        return response()->json($this->shape($record));
    }

    /** @return array<string, mixed> */
    private function shape(AmenityBooking $b): array
    {
        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'amenity' => ['id' => $b->amenity_id, 'name' => $b->amenity->name],
            'starts_at' => $b->starts_at->toIso8601String(),
            'ends_at' => $b->ends_at->toIso8601String(),
            'guests' => $b->guests,
            'status' => $b->status,
            'booking_fee' => Decimal::of($b->fee_minor),
            'deposit' => Decimal::of($b->deposit_minor),
            'deposit_state' => $b->deposit_state,
            'currency' => $b->currency,
            'cancellable_until' => $b->cancellation_hours === null ? $b->starts_at->toIso8601String() : $b->starts_at->copy()->subHours($b->cancellation_hours)->toIso8601String(),
            'declined_reason' => $b->declined_reason,
            'cancelled_at' => $b->cancelled_at?->toIso8601String(),
        ];
    }
}
