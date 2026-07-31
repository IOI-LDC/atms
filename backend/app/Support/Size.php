<?php

namespace App\Support;

use App\Exceptions\InvalidSizeFormatException;

/**
 * An exact inch measurement for an Asset or a Part.
 *
 * Sizes are always inches. The value is held as an integer count of hundred-
 * thousandths of an inch so arithmetic and equality stay exact — a float would
 * make 6 3/4" and 6.75" fail to match, which is the whole point of the
 * compatibility rule. The scale mirrors the `numeric(9,5)` storage column:
 * 5 decimal places, because 1/32 = 0.03125 needs exactly five and anything
 * coarser would round it away.
 *
 * Parsing is strict and whole-cell by design. Size comes only from the
 * dedicated workbook Size column and is never scraped out of a name or
 * description — `AssetTagService` does that for legacy tag generation and is
 * deliberately not reused here.
 */
final class Size
{
    /** Decimal places retained; matches the `numeric(9,5)` column. */
    private const SCALE = 5;

    /** 10 ** self::SCALE — multiplier between inches and the internal integer. */
    private const SCALE_FACTOR = 100000;

    /** Scaled value of 1/32", the finest fraction rendered in O&G notation. */
    private const THIRTY_SECOND = self::SCALE_FACTOR / 32;

    /** `numeric(9,5)` holds 9 total digits, so 9999.99999 is the ceiling. */
    private const MAX_SCALED = 999999999;

    /** Curly quotes and the prime glyph all mean "inches". */
    private const QUOTE_GLYPHS = ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", "\u{2033}", "\u{2032}"];

    private const FRACTION_GLYPHS = [
        "\u{00BD}" => '1/2',
        "\u{00BC}" => '1/4',
        "\u{00BE}" => '3/4',
        "\u{215B}" => '1/8',
        "\u{215C}" => '3/8',
        "\u{215D}" => '5/8',
        "\u{215E}" => '7/8',
        "\u{2153}" => '1/3',
        "\u{2154}" => '2/3',
    ];

    private function __construct(private readonly int $scaledInches) {}

    /**
     * Parse a workbook Size cell.
     *
     * Returns null for a blank cell — Size is nullable, and a blank means
     * "applies at every size" under the compatibility rule rather than an
     * error. A non-blank cell that is not an exact inch measurement throws,
     * so the import validator can reject the whole workbook.
     *
     * @throws InvalidSizeFormatException
     */
    public static function fromWorkbookCell(string|int|float|null $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        return self::parse($text);
    }

    /**
     * Rehydrate from the exact decimal string held in the database.
     *
     * @throws InvalidSizeFormatException
     */
    public static function fromCanonical(string $decimal): self
    {
        return self::parse(trim($decimal));
    }

    /**
     * The exact value for storage in the `numeric(9,5)` column, e.g. "6.75000".
     *
     * Always fixed-width, so two equal sizes are byte-identical and index and
     * compare correctly regardless of how they were written in the workbook.
     */
    public function canonical(): string
    {
        $whole = intdiv($this->scaledInches, self::SCALE_FACTOR);
        $fraction = $this->scaledInches % self::SCALE_FACTOR;

        return $whole.'.'.str_pad((string) $fraction, self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * Render in standard O&G notation, e.g. `6 3/4"`, `9 5/8"`, `1 1/32"`, `8"`.
     *
     * Values that are exact multiples of 1/32 render as fractions. Anything
     * finer renders as a trimmed decimal rather than being forced into a
     * fraction it does not actually equal.
     */
    public function format(): string
    {
        return $this->isThirtySecondMultiple()
            ? $this->formatAsFraction()
            : $this->formatAsDecimal();
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $this->scaledInches === $other->scaledInches;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @throws InvalidSizeFormatException
     */
    private static function parse(string $raw): self
    {
        $text = self::normalize($raw);

        if ($text === '') {
            throw new InvalidSizeFormatException($raw, 'Size is empty once the inch mark is removed.');
        }

        // Mixed number: "6 3/4" or "6-3/4".
        if (preg_match('/^(\d+)[\s-]+(\d+)\/(\d+)$/', $text, $m)) {
            [$whole, $numerator, $denominator] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            if ($numerator >= $denominator) {
                throw new InvalidSizeFormatException($raw, "Mixed number has an improper fraction: {$numerator}/{$denominator}.");
            }

            return self::fromScaled(
                $whole * self::SCALE_FACTOR + self::scaledFraction($raw, $numerator, $denominator),
                $raw
            );
        }

        // Bare fraction: "3/4".
        if (preg_match('/^(\d+)\/(\d+)$/', $text, $m)) {
            return self::fromScaled(self::scaledFraction($raw, (int) $m[1], (int) $m[2]), $raw);
        }

        // Whole or decimal: "8" or "6.75".
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $text, $m)) {
            $decimals = $m[2] ?? '';

            if (strlen($decimals) > self::SCALE) {
                throw new InvalidSizeFormatException($raw, 'Size has more than '.self::SCALE.' decimal places and cannot be stored exactly.');
            }

            $scaled = (int) $m[1] * self::SCALE_FACTOR
                + (int) str_pad($decimals, self::SCALE, '0', STR_PAD_RIGHT);

            return self::fromScaled($scaled, $raw);
        }

        throw new InvalidSizeFormatException($raw, 'Size is not a recognised inch measurement.');
    }

    /**
     * Strip inch marks and unify the many ways a workbook spells a fraction.
     */
    private static function normalize(string $raw): string
    {
        $text = str_replace(self::QUOTE_GLYPHS, '"', $raw);

        $expanded = strtr($text, self::FRACTION_GLYPHS);
        $hadGlyphFraction = $expanded !== $text;
        $text = $expanded;

        // Typewriter inch marks: 6.75'' → 6.75"
        $text = str_replace("''", '"', $text);

        // Trailing unit, however it is written.
        $text = preg_replace('/\s*(?:"|in|inch|inches)\s*$/i', '', $text) ?? '';

        // A glyph fraction butts straight against its whole number ("6¾"), so
        // reinstate the separator the glyph implied. Only when a glyph was
        // actually expanded — doing it unconditionally would rewrite a bare
        // improper fraction like "123/4" into the mixed number "1 23/4".
        if ($hadGlyphFraction) {
            $text = preg_replace('/(\d)(?=\d+\/\d+$)/', '$1 ', $text) ?? '';
        }

        return trim($text);
    }

    /**
     * @throws InvalidSizeFormatException
     */
    private static function scaledFraction(string $raw, int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidSizeFormatException($raw, 'Size has a zero denominator.');
        }

        $scaled = $numerator * self::SCALE_FACTOR;

        if ($scaled % $denominator !== 0) {
            throw new InvalidSizeFormatException($raw, "Size {$numerator}/{$denominator} is not exactly representable to ".self::SCALE.' decimal places.');
        }

        return intdiv($scaled, $denominator);
    }

    /**
     * @throws InvalidSizeFormatException
     */
    private static function fromScaled(int $scaled, string $raw): self
    {
        if ($scaled <= 0) {
            throw new InvalidSizeFormatException($raw, 'Size must be greater than zero.');
        }

        if ($scaled > self::MAX_SCALED) {
            throw new InvalidSizeFormatException($raw, 'Size exceeds the maximum storable value of 9999.99999 inches.');
        }

        return new self($scaled);
    }

    private function isThirtySecondMultiple(): bool
    {
        return $this->scaledInches % self::THIRTY_SECOND === 0;
    }

    private function formatAsFraction(): string
    {
        $thirtySeconds = intdiv($this->scaledInches, self::THIRTY_SECOND);
        $whole = intdiv($thirtySeconds, 32);
        $remainder = $thirtySeconds % 32;

        if ($remainder === 0) {
            return $whole.'"';
        }

        $divisor = self::greatestCommonDivisor($remainder, 32);
        $fraction = ($remainder / $divisor).'/'.(32 / $divisor);

        return $whole === 0 ? $fraction.'"' : $whole.' '.$fraction.'"';
    }

    private function formatAsDecimal(): string
    {
        return rtrim(rtrim($this->canonical(), '0'), '.').'"';
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
