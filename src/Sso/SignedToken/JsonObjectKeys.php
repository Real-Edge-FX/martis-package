<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

/**
 * Finds a key that an object of a JSON text repeats, at any depth.
 *
 * `json_decode()` keeps the last of two equal keys and says nothing, so a
 * token whose payload or footer says `"sub"` twice reads as one thing here and
 * as another to a parser that keeps the first. The issuer's own tokens are the
 * `json_encode()` of a PHP array and never repeat a key; a signed token that
 * does is refused as malformed.
 *
 * The scan is one pass over the text, linear in its length (a token is bounded
 * by {@see PasetoV4Public::MAX_TOKEN_LENGTH}). Keys are compared as decoded
 * strings, so `"sub"` and `"sub"` collide, and a key inside a string
 * VALUE is never mistaken for one.
 */
final class JsonObjectKeys
{
    /**
     * Whether any object of `$json` repeats a key. The text must be valid
     * JSON already (`json_decode()` has accepted it): on anything the scan
     * cannot follow it answers true, so a text it does not understand is never
     * let through as clean.
     */
    public static function hasRepeatedKey(string $json): bool
    {
        $length = strlen($json);
        /** @var list<array<string, true>|null> $stack one entry per open container: the keys seen of an object, null for an array */
        $stack = [];
        $expectKey = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            switch ($char) {
                case '{':
                    $stack[] = [];
                    $expectKey = true;
                    break;

                case '[':
                    $stack[] = null;
                    $expectKey = false;
                    break;

                case '}':
                case ']':
                    array_pop($stack);
                    $expectKey = false;
                    break;

                case ',':
                    $expectKey = $stack !== [] && $stack[array_key_last($stack)] !== null;
                    break;

                case '"':
                    $end = self::stringEnd($json, $i, $length);
                    if ($end === null) {
                        return true;
                    }

                    if ($expectKey) {
                        $key = json_decode(substr($json, $i, $end - $i + 1));
                        if (! is_string($key)) {
                            return true;
                        }

                        $top = array_key_last($stack);
                        if ($top === null || isset($stack[$top][$key])) {
                            return true;
                        }

                        $stack[$top][$key] = true;
                        $expectKey = false;
                    }

                    $i = $end;
                    break;
            }
        }

        return false;
    }

    /** The offset of the quote that closes the string opened at `$start`, null when it never closes. */
    private static function stringEnd(string $json, int $start, int $length): ?int
    {
        $i = $start + 1;

        while ($i < $length) {
            $i += strcspn($json, '"\\', $i);

            if ($i >= $length) {
                return null;
            }

            if ($json[$i] === '"') {
                return $i;
            }

            $i += 2; // a backslash and the character it escapes
        }

        return null;
    }
}
