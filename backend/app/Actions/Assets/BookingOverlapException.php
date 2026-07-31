<?php

namespace App\Actions\Assets;

use App\Models\Booking;
use DomainException;
use Illuminate\Support\Collection;

class BookingOverlapException extends DomainException
{
    /**
     * @param  Collection<int, Booking>  $conflicts
     */
    public function __construct(public readonly Collection $conflicts)
    {
        parent::__construct('Asset already has an active booking overlapping this date range.');
    }
}
