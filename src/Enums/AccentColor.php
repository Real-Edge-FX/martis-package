<?php

namespace Martis\Enums;

/**
 * Accent colors shipped by the Martis design system.
 *
 * `Custom` is a sentinel — when set, `user_preferences.brand_color`
 * (a hex string) drives the accent via perceptual-luminance derivation.
 */
enum AccentColor: string
{
    case Martis = 'martis';
    case Blue = 'blue';
    case Teal = 'teal';
    case Violet = 'violet';
    case Amber = 'amber';
    case Custom = 'custom';

    /** @return list<string> */
    public static function presetValues(): array
    {
        return array_values(array_filter(
            array_map(fn (self $c) => $c->value, self::cases()),
            fn (string $v) => $v !== self::Custom->value,
        ));
    }
}
