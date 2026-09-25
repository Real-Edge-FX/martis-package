<?php

namespace Martis\Support;

/**
 * A translation line as a string.
 *
 * `__()` and `trans()` return an array when the key names a group of lines
 * (`martis::messages` instead of `martis::messages.unauthorized`), so their
 * result cannot go straight into a `string` parameter. A key that resolves to
 * a group, which only happens when an application overrides a Martis line
 * with an array, falls back to the key itself, as a missing line does.
 */
final class TranslatedLine
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
