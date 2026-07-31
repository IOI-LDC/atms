<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a workbook Size cell holds a non-blank value that cannot be
 * stored as an exact canonical inch measurement.
 *
 * Carries the offending raw cell alongside a human-readable reason so the
 * import validator can report workbook, sheet, row, identifier, field and
 * reason for the failure without re-deriving any of it.
 */
class InvalidSizeFormatException extends DomainException
{
    public function __construct(public readonly string $rawValue, string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
