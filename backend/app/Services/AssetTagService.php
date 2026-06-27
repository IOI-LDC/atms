<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\FaSubclassTypeCode;

class AssetTagService
{
    /**
     * Format: L-BBB-CCC-XXXX (max 15 chars)
     * L    = Ownership code (always L for LDC-managed)
     * BBB  = 3-letter type code (from FaSubclassTypeCode or keyword detection)
     * CCC  = 3-digit size code (parsed from description, truncated to 3)
     * XXXX = 4-char serial suffix (last 4 chars of serial_number)
     */
    public function generateTag(Asset $asset): ?string
    {
        $ownership = 'L';
        $typeCode = $this->resolveTypeCode($asset->fa_subclass_code, $asset->description);
        $sizeCode = $this->resolveSizeCode($asset->description);
        $serialSuffix = $this->resolveSerialSuffix($asset->serial_number, $asset->erp_asset_code);

        $tag = "{$ownership}-{$typeCode}-{$sizeCode}-{$serialSuffix}";

        if (Asset::where('asset_tag', $tag)->exists()) {
            return null;
        }

        return $tag;
    }

    /**
     * Resolve the 3-letter type code.
     *
     * Checks the description for ROTOR / STATOR keywords first (since ERP lumps
     * them under MUD MOTOR). Falls back to the FaSubclassTypeCode lookup table.
     * Returns 'UNK' if no match.
     */
    private function resolveTypeCode(?string $faSubclassCode, ?string $description): string
    {
        $desc = $description !== null ? mb_strtoupper($description) : '';

        // Rotors and Stators are components, not motors — detect by keyword
        if (str_contains($desc, 'ROTOR') && ! str_contains($desc, 'STATOR')) {
            return 'RTR';
        }

        if (str_contains($desc, 'STATOR') && ! str_contains($desc, 'ROTOR')) {
            return 'STR';
        }

        if ($faSubclassCode === null) {
            return 'UNK';
        }

        $entry = FaSubclassTypeCode::where('fa_subclass_code', $faSubclassCode)->first();

        return $entry?->type_code ?: 'UNK';
    }

    /**
     * Extract a 3-digit size code from the description.
     *
     * Matches the first inch-pattern (e.g. 9 5/8", 1.25", 8") and encodes:
     *   - Fractional: "9 5/8" → "958"
     *   - Decimal:    "1.25"  → "125"
     *   - Whole:      "8"     → "800"
     * Truncates to exactly 3 chars from the right. Returns "000" if no match.
     */
    private function resolveSizeCode(?string $description): string
    {
        if ($description === null) {
            return '000';
        }

        // Normalize curly/smart quotes to straight quotes for regex matching
        $normalized = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], // " " ' '
            ['"', '"', "'", "'"],
            $description
        );

        // Normalize Unicode fraction glyphs to ASCII fraction notation
        $normalized = str_replace(
            ["\u{00BD}", "\u{00BC}", "\u{00BE}", "\u{215B}", "\u{215C}", "\u{215D}", "\u{215E}"],
            ['1/2', '1/4', '3/4', '1/8', '3/8', '5/8', '7/8'],
            $normalized
        );

        // Normalize double-apostrophe inch notation to standard quote
        // e.g. "4.75''NONMAG..." → "4.75\"NONMAG..."
        $normalized = preg_replace('/(\d)\'\'/', '$1"', $normalized);

        // 1. Try foot measurement (e.g. "100 FT", "50 ft", "10FT")
        if (preg_match('/([\dO]+)\s*(?:FT|ft|Ft)\b/', $normalized, $ftMatches)) {
            $feet = str_replace('O', '0', strtoupper($ftMatches[1]));

            return str_pad($feet, 3, '0', STR_PAD_LEFT);
        }

        // 2. Try inch measurement (e.g. '9 5/8"', '1.25"', '8"')
        if (preg_match('/(\d+(?:\.\d+)?(?:[\s-]+\d+\/\d+)?)\s*"/', $normalized, $matches)) {
            $value = $matches[1];

            // Handle trailing ".000" as a whole number (e.g. 7.000" → 7")
            if (str_contains($value, '.') && ! str_contains($value, '/')) {
                if (preg_match('/\.0+$/', $value)) {
                    $value = (string) ((int) $value);
                } else {
                    $value = $this->decimalToFraction($value);
                }
            }

            if (str_contains($value, '/')) {
                $cleaned = str_replace([' ', '/', '-'], '', $value);
            } elseif (str_contains($value, '.')) {
                $cleaned = str_replace('.', '', $value);
            } else {
                $cleaned = $value.'00';
            }

            // Truncate to the rightmost 3 chars (e.g. "1212" → "212")
            if (strlen($cleaned) > 3) {
                $cleaned = substr($cleaned, -3);
            }

            return str_pad($cleaned, 3, '0', STR_PAD_LEFT);
        }

        return '000';
    }

    /**
     * Convert common oilfield decimal sizes to fractional form.
     *
     * e.g. 12.125" → "12 1/8"  —  .125 = 1/8
     *      8.75"   → "8 3/4"   —  .75  = 3/4
     *
     * Non-standard decimals are returned unchanged.
     */
    private function decimalToFraction(string $value): string
    {
        $fractionMap = [
            '.125' => ' 1/8',
            '.25' => ' 1/4',
            '.375' => ' 3/8',
            '.5' => ' 1/2',
            '.625' => ' 5/8',
            '.75' => ' 3/4',
            '.875' => ' 7/8',
        ];

        foreach ($fractionMap as $decimal => $fraction) {
            if (str_ends_with($value, $decimal)) {
                $whole = substr($value, 0, -strlen($decimal));

                return $whole.$fraction;
            }
        }

        return $value; // unknown decimal, keep as-is
    }

    /**
     * Extract a 4-character suffix from the serial number.
     *
     * Extracts all digits from the serial, takes the last 4, and zero-pads.
     * This avoids collisions from non-digit separators (dashes, hyphens) that
     * would otherwise produce identical short suffixes (e.g. "-18" vs "-18").
     *
     * Falls back to the last 4 chars of the ERP asset code
     * (e.g. FA000411 → "0411") when no serial exists.
     */
    private function resolveSerialSuffix(?string $serialNumber, ?string $erpAssetCode): string
    {
        if ($serialNumber === null || $serialNumber === '') {
            $fallback = $erpAssetCode !== null ? mb_substr($erpAssetCode, -4) : '';

            return str_pad($fallback, 4, '0', STR_PAD_LEFT);
        }

        // Extract all digits, take last 4 (e.g. "4-C42220-17" → "44222017" → "2017")
        $digits = preg_replace('/[^0-9]/', '', $serialNumber);
        $last4 = substr($digits, -4) ?: '0000';

        return str_pad($last4, 4, '0', STR_PAD_LEFT);
    }
}
