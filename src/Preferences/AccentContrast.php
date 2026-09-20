<?php

declare(strict_types=1);

namespace Martis\Preferences;

/**
 * Picks the `--martis-accent-contrast` colour (the text / icon colour painted
 * on top of an accent fill) for an accent hex the package derives a palette
 * from: named custom accents (`MARTIS_CUSTOM_ACCENTS`) and the per-user
 * `brandColor`.
 *
 * White stays the answer as long as it reaches the WCAG 3:1 floor for UI
 * components and large text against the accent (every bundled accent and
 * the usual brand blues / violets / teals); below that floor (lime, cyan,
 * amber, yellow…) a near-black navy is used instead. A pure "highest ratio"
 * rule would paint navy on the package's own #4F7BF9 (3.8:1 white vs 4.9:1
 * navy), which nobody expects from a primary button.
 *
 * `resources/js/lib/accentContrast.ts` and the inline boot script in
 * `app.blade.php` implement the same rule client-side; keep the three in
 * sync so the UI never flashes between two contrast colours on load.
 */
final class AccentContrast
{
    public const LIGHT = '#ffffff';

    public const DARK = '#0b1220';

    /** WCAG 1.4.11 / 1.4.3 (large text) minimum contrast ratio. */
    public const MIN_LIGHT_RATIO = 3.0;

    /**
     * @param  string  $hex  `#RGB`, `#RGBA`, `#RRGGBB` or `#RRGGBBAA` (alpha ignored)
     */
    public static function for(string $hex): string
    {
        $rgb = self::rgb($hex);

        if ($rgb === null) {
            return self::LIGHT;
        }

        $accent = self::relativeLuminance($rgb);
        $light = self::relativeLuminance([255, 255, 255]);

        return self::ratio($accent, $light) >= self::MIN_LIGHT_RATIO ? self::LIGHT : self::DARK;
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function rgb(string $hex): ?array
    {
        $value = ltrim(trim($hex), '#');

        if (! preg_match('/^[0-9a-f]{3,4}$|^[0-9a-f]{6}$|^[0-9a-f]{8}$/i', $value)) {
            return null;
        }

        if (strlen($value) <= 4) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return [
            (int) hexdec(substr($value, 0, 2)),
            (int) hexdec(substr($value, 2, 2)),
            (int) hexdec(substr($value, 4, 2)),
        ];
    }

    /**
     * WCAG 2.x relative luminance of an sRGB colour.
     *
     * @param  array{int, int, int}  $rgb
     */
    private static function relativeLuminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function ratio(float $a, float $b): float
    {
        [$lighter, $darker] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
