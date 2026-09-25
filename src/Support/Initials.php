<?php

namespace Martis\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * The initials and colour of an identity avatar. The Avatar and UiAvatar
 * fields, the Topbar and the profile page all take theirs from here, so a
 * person gets the same letters on the same colour everywhere.
 *
 * The colour is a slot of the theme's avatar palette, the tokens
 * `--martis-avatar-1` to `--martis-avatar-16`: the frontend paints
 * `var(--martis-avatar-{slot})`, so a theme recolours every avatar. The slot
 * is a hash of the seed (a name, an e-mail), the same hash as
 * `avatarPaletteSlot()` in `resources/js/lib/avatarPalette.ts`, so a seed
 * gets the same slot on the server and in the browser.
 */
final class Initials
{
    /** Number of `--martis-avatar-N` tokens in the theme palette. */
    public const PALETTE_SIZE = 16;

    /**
     * The default value of each `--martis-avatar-N` token
     * (`resources/css/martis.css`), for a payload that also carries the
     * literal colour: the `color` key of the Avatar and UiAvatar fields.
     *
     * @var array<int, string>
     */
    public const DEFAULT_COLORS = [
        1 => '#2563eb',  // blue-600
        2 => '#059669',  // emerald-600
        3 => '#db2777',  // pink-600
        4 => '#d97706',  // amber-600
        5 => '#7c3aed',  // violet-600
        6 => '#0891b2',  // cyan-600
        7 => '#ea580c',  // orange-600
        8 => '#dc2626',  // red-600
        9 => '#16a34a',  // green-600
        10 => '#9333ea', // purple-600
        11 => '#4f46e5', // indigo-600
        12 => '#0d9488', // teal-600
        13 => '#c026d3', // fuchsia-600
        14 => '#65a30d', // lime-600
        15 => '#be185d', // rose-700
        16 => '#475569', // slate-600
    ];

    /**
     * The initials of a seed: the first letter of its first word and of its
     * last word, upper-cased ("Ada Lovelace" gives "AL", "Ada" gives "A").
     * A blank seed has none.
     */
    public static function of(string $seed): string
    {
        $words = preg_split('/\s+/u', trim($seed), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * The palette slot of a seed, from 1 to 16. The hash runs over the
     * seed's UTF-16 code units, as a JavaScript string's `charCodeAt()`
     * reads them (djb2, taken as a signed 32-bit integer), so the browser
     * computes the same slot. An empty seed gets slot 16, the neutral slate.
     */
    public static function paletteSlot(string $seed): int
    {
        if ($seed === '') {
            return self::PALETTE_SIZE;
        }

        $hash = 5381;
        foreach (unpack('n*', (string) mb_convert_encoding($seed, 'UTF-16BE', 'UTF-8')) ?: [] as $unit) {
            $hash = (($hash << 5) + $hash + (int) $unit) & 0xFFFFFFFF;
        }

        // JavaScript reads the 32 bits as a signed integer before Math.abs().
        $magnitude = $hash >= 0x80000000 ? 0x100000000 - $hash : $hash;

        return $magnitude % self::PALETTE_SIZE + 1;
    }

    /**
     * The colour of a palette slot in the built-in theme.
     *
     * @throws InvalidArgumentException when `$slot` is not from 1 to 16
     */
    public static function defaultColor(int $slot): string
    {
        return self::DEFAULT_COLORS[$slot]
            ?? throw new InvalidArgumentException("Avatar palette slot {$slot} does not exist: the slots are 1 to ".self::PALETTE_SIZE.'.');
    }

    /**
     * The avatar keys of the user payload (`/api/auth/user`, the login
     * response, `/api/profile`): the initials and palette slot of the user's
     * name, or of the e-mail when the name is blank.
     *
     * @return array{avatar_initials: string, avatar_palette: int}
     */
    public static function forUser(Authenticatable $user): array
    {
        $seed = '';
        foreach (['name', 'email'] as $attribute) {
            $value = data_get($user, $attribute);
            if (is_string($value) && trim($value) !== '') {
                $seed = $value;
                break;
            }
        }

        return [
            'avatar_initials' => self::of($seed),
            'avatar_palette' => self::paletteSlot($seed),
        ];
    }
}
