<?php

namespace Tests\Unit\Size;

use App\Exceptions\InvalidSizeFormatException;
use App\Support\Size;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SizeTest extends TestCase
{
    /**
     * Every spelling of the same measurement must land on one stored value —
     * this is what makes the Category/Size compatibility rule work.
     *
     * @return array<string, array{string, string}>
     */
    public static function equivalentInputsProvider(): array
    {
        return [
            'mixed number' => ['6 3/4', '6.75000'],
            'mixed number with inch mark' => ['6 3/4"', '6.75000'],
            'mixed number hyphenated' => ['6-3/4', '6.75000'],
            'mixed number hyphenated with mark' => ['6-3/4"', '6.75000'],
            'decimal' => ['6.75', '6.75000'],
            'decimal with inch mark' => ['6.75"', '6.75000'],
            'decimal typewriter mark' => ["6.75''", '6.75000'],
            'curly close quote' => ["6 3/4\u{201D}", '6.75000'],
            'prime glyph' => ["6 3/4\u{2033}", '6.75000'],
            'glyph fraction spaced' => ["6 \u{00BE}", '6.75000'],
            'glyph fraction butted' => ["6\u{00BE}", '6.75000'],
            'trailing in' => ['6.75 in', '6.75000'],
            'trailing inch' => ['6.75 inch', '6.75000'],
            'trailing inches' => ['6.75 INCHES', '6.75000'],
            'surrounding whitespace' => ['   6 3/4"   ', '6.75000'],
            'trailing zeros' => ['6.7500', '6.75000'],
        ];
    }

    #[DataProvider('equivalentInputsProvider')]
    public function test_equivalent_inputs_normalize_to_one_canonical_value(string $input, string $expected): void
    {
        $this->assertSame($expected, Size::fromWorkbookCell($input)?->canonical());
    }

    public function test_all_equivalent_spellings_compare_equal(): void
    {
        $canonical = Size::fromWorkbookCell('6 3/4');

        foreach (array_column(self::equivalentInputsProvider(), 0) as $input) {
            $this->assertTrue(
                $canonical->equals(Size::fromWorkbookCell($input)),
                "[{$input}] should equal 6 3/4\""
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fractionalDisplayProvider(): array
    {
        return [
            'whole number' => ['8', '8"'],
            'whole from decimal' => ['8.0', '8"'],
            'half' => ['9.5', '9 1/2"'],
            'quarter' => ['6.25', '6 1/4"'],
            'three quarters' => ['6.75', '6 3/4"'],
            'eighth' => ['9.625', '9 5/8"'],
            'sixteenth' => ['12.0625', '12 1/16"'],
            'thirty-second' => ['1.03125', '1 1/32"'],
            'bare thirty-second' => ['1/32', '1/32"'],
            'bare fraction' => ['3/4', '3/4"'],
            'reduces 16/32' => ['0.5', '1/2"'],
            'reduces 24/32' => ['0.75', '3/4"'],
            'reduces 2/32' => ['0.0625', '1/16"'],
            'large O&G size' => ['17.5', '17 1/2"'],
        ];
    }

    #[DataProvider('fractionalDisplayProvider')]
    public function test_formats_standard_og_fractions_through_thirty_seconds(string $input, string $expected): void
    {
        $this->assertSame($expected, Size::fromWorkbookCell($input)?->format());
    }

    /**
     * A value finer than 1/32 is stored exactly but must not be forced into a
     * fraction it does not equal — it falls back to a trimmed decimal.
     *
     * @return array<string, array{string, string}>
     */
    public static function nonFractionalDisplayProvider(): array
    {
        return [
            'one decimal' => ['4.7', '4.7"'],
            'two decimals' => ['4.72', '4.72"'],
            'sixty-fourth' => ['0.01563', '0.01563"'],
            'five decimals' => ['3.14159', '3.14159"'],
        ];
    }

    #[DataProvider('nonFractionalDisplayProvider')]
    public function test_values_finer_than_a_thirty_second_render_as_decimals(string $input, string $expected): void
    {
        $this->assertSame($expected, Size::fromWorkbookCell($input)?->format());
    }

    /**
     * @return array<string, array{string|int|float|null}>
     */
    public static function blankCellProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    /**
     * A blank Size is not an error — under the compatibility rule it means
     * "applies at every size".
     */
    #[DataProvider('blankCellProvider')]
    public function test_blank_cell_is_null_not_an_error(string|int|float|null $input): void
    {
        $this->assertNull(Size::fromWorkbookCell($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidCellProvider(): array
    {
        return [
            'not representable in five places' => ['1/3', 'not exactly representable'],
            'zero denominator' => ['1/0', 'zero denominator'],
            'zero' => ['0', 'greater than zero'],
            'zero decimal' => ['0.0', 'greater than zero'],
            'too many decimals' => ['6.123456', 'more than 5 decimal places'],
            'exceeds column ceiling' => ['10000', 'exceeds the maximum'],
            'improper mixed fraction' => ['6 5/4', 'improper fraction'],
            'free text' => ['LARGE', 'not a recognised inch measurement'],
            'size embedded in prose' => ['6 3/4" MUD MOTOR', 'not a recognised inch measurement'],
            'two sizes in one cell' => ['9 1/2" x 7 5/8"', 'not a recognised inch measurement'],
            'negative' => ['-6', 'not a recognised inch measurement'],
            'metric' => ['150mm', 'not a recognised inch measurement'],
            'inch mark only' => ['"', 'empty once the inch mark is removed'],
        ];
    }

    #[DataProvider('invalidCellProvider')]
    public function test_invalid_cell_throws_with_a_reportable_reason(string $input, string $expectedReason): void
    {
        try {
            Size::fromWorkbookCell($input);
            $this->fail("[{$input}] should have been rejected.");
        } catch (InvalidSizeFormatException $e) {
            $this->assertStringContainsString($expectedReason, $e->reason());
            $this->assertSame($input, $e->rawValue);
        }
    }

    /**
     * Size comes only from the dedicated workbook column. Anything that looks
     * like a name or description is rejected outright rather than scraped —
     * the opposite of what AssetTagService does for legacy tag generation.
     */
    public function test_never_scrapes_a_size_out_of_a_name(): void
    {
        $names = [
            '2-7/8" 9/10 1.4 ROTOR',
            '9 ½ " Float Sub (7 5/8 REG PIN - 7 5/8 REG BOX)',
            '16" OD Motor Stabilizer Sleeve',
            'R675785.0-SLD-2.875 REG:WC-4.00 ROTOR',
        ];

        foreach ($names as $name) {
            $rejected = false;

            try {
                Size::fromWorkbookCell($name);
            } catch (InvalidSizeFormatException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, "[{$name}] must not parse as a Size.");
        }
    }

    public function test_canonical_is_fixed_width_for_storage(): void
    {
        $this->assertSame('8.00000', Size::fromWorkbookCell('8')?->canonical());
        $this->assertSame('0.03125', Size::fromWorkbookCell('1/32')?->canonical());
        $this->assertSame('9999.99999', Size::fromWorkbookCell('9999.99999')?->canonical());
    }

    public function test_round_trips_through_canonical_storage(): void
    {
        foreach (['6 3/4', '1/32', '8', '4.7', '17 1/2'] as $input) {
            $original = Size::fromWorkbookCell($input);
            $rehydrated = Size::fromCanonical($original->canonical());

            $this->assertTrue($original->equals($rehydrated));
            $this->assertSame($original->format(), $rehydrated->format());
        }
    }

    public function test_accepts_numeric_cell_types_from_the_spreadsheet_reader(): void
    {
        $this->assertSame('8.00000', Size::fromWorkbookCell(8)?->canonical());
        $this->assertSame('6.75000', Size::fromWorkbookCell(6.75)?->canonical());
    }

    public function test_differing_sizes_are_not_equal(): void
    {
        $sixThreeQuarters = Size::fromWorkbookCell('6 3/4');

        $this->assertFalse($sixThreeQuarters->equals(Size::fromWorkbookCell('6 1/2')));
        $this->assertFalse($sixThreeQuarters->equals(null));
    }

    public function test_stringifies_as_the_display_format(): void
    {
        $this->assertSame('6 3/4"', (string) Size::fromWorkbookCell('6.75'));
    }
}
