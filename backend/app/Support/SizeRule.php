<?php

namespace App\Support;

use App\Exceptions\InvalidSizeFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a submitted Size is an exact inch measurement.
 *
 * Without this the malformed value reaches the Eloquent cast, which throws
 * InvalidSizeFormatException — a DomainException, so controllers translate it
 * to 409. A bad input string is a validation failure, not a domain conflict, so
 * it belongs in the 422 body with a field-level message the form can show.
 *
 * Lives in Support rather than a new `app/Rules` directory to stay inside the
 * existing structure, alongside {@see Size} and {@see SizeCast}.
 */
class SizeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('The :attribute must be an inch measurement.');

            return;
        }

        try {
            Size::fromWorkbookCell($value);
        } catch (InvalidSizeFormatException $e) {
            $fail($e->reason());
        }
    }
}
