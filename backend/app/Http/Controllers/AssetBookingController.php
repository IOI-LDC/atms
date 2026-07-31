<?php

namespace App\Http\Controllers;

use App\Actions\Assets\BookingOverlapException;
use App\Actions\Assets\CancelAssetBooking;
use App\Actions\Assets\CreateAssetBooking;
use App\Actions\Assets\UpdateAssetBooking;
use App\Http\Resources\BookingResource;
use App\Models\Asset;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetBookingController extends Controller
{
    /**
     * List bookings for an asset (active + history).
     */
    public function index(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('viewAny', Booking::class);

        $bookings = $asset->bookings()
            ->with('bookedBy')
            ->orderByDesc('booked_from')
            ->get();

        return BookingResource::collection($bookings)->response();
    }

    /**
     * Create a booking on an asset.
     */
    public function store(Request $request, Asset $asset, CreateAssetBooking $action): JsonResponse
    {
        Gate::authorize('create', Booking::class);

        $validated = $request->validate([
            'booked_from' => ['required', 'date'],
            'booked_until' => ['required', 'date', 'after_or_equal:booked_from'],
            'booking_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $booking = $action->execute($asset, $request->user(), $validated);

            return (new BookingResource($booking))
                ->additional(['message' => 'Asset booked.'])
                ->response()
                ->setStatusCode(201);
        } catch (BookingOverlapException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'conflicts' => BookingResource::collection($e->conflicts),
            ], 409);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Update an active booking (dates, reference, notes).
     */
    public function update(Request $request, Asset $asset, Booking $booking, UpdateAssetBooking $action): JsonResponse
    {
        Gate::authorize('update', $booking);

        if ($booking->asset_id !== $asset->id) {
            return response()->json(['message' => 'Booking does not belong to this asset.'], 404);
        }

        $validated = $request->validate([
            'booked_from' => ['sometimes', 'required', 'date'],
            'booked_until' => ['sometimes', 'required', 'date', 'after_or_equal:booked_from'],
            'booking_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $booking = $action->execute($booking, $validated);

            return (new BookingResource($booking))
                ->additional(['message' => 'Booking updated.'])
                ->response();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Cancel an active booking.
     */
    public function cancel(Request $request, Asset $asset, Booking $booking, CancelAssetBooking $action): JsonResponse
    {
        Gate::authorize('cancel', $booking);

        if ($booking->asset_id !== $asset->id) {
            return response()->json(['message' => 'Booking does not belong to this asset.'], 404);
        }

        try {
            $booking = $action->execute($booking);

            return (new BookingResource($booking))
                ->additional(['message' => 'Booking cancelled.'])
                ->response();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
