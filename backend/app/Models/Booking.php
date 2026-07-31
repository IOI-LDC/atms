<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'asset_id',
        'booked_by',
        'booked_from',
        'booked_until',
        'booking_reference',
        'notes',
        'status',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'booked_from' => 'date',
            'booked_until' => 'date',
            'status' => BookingStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /**
     * Scope: only active bookings.
     */
    public function scopeActive($query)
    {
        return $query->where('status', BookingStatus::ACTIVE);
    }

    /**
     * Scope: active bookings covering a given date (default today).
     */
    public function scopeCoveringDate($query, ?string $date = null)
    {
        $date = $date ?? now()->toDateString();

        return $query->active()
            ->where('booked_from', '<=', $date)
            ->where('booked_until', '>=', $date);
    }

    /**
     * Scope: active bookings overlapping a date range.
     */
    public function scopeOverlapping($query, string $from, string $until)
    {
        return $query->active()
            ->where('booked_from', '<=', $until)
            ->where('booked_until', '>=', $from);
    }
}
