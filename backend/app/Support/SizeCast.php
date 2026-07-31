<?php

namespace App\Support;

use App\Exceptions\InvalidSizeFormatException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a `numeric(9,5)` inch column to and from {@see Size}.
 *
 * Lives in Support rather than a new `app/Casts` directory to stay inside the
 * existing structure.
 *
 * @implements CastsAttributes<Size|null, Size|string|null>
 */
class SizeCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidSizeFormatException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Size
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Size::fromCanonical((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidSizeFormatException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Size) {
            return $value->canonical();
        }

        return Size::fromWorkbookCell($value)?->canonical();
    }
}
