<?php

declare(strict_types=1);

namespace Martis\Preferences;

use InvalidArgumentException;

/**
 * Parses the `MARTIS_UI_LOCALE_LABELS` env value into a `[code => label]`
 * map for the language pickers (v2.10.0).
 *
 * Format: comma-separated `code:label` pairs. The first colon splits, so a
 * label may contain further colons; a label cannot contain a comma.
 * Whitespace around the code and the label is trimmed.
 *
 *     MARTIS_UI_LOCALE_LABELS="en_GB:English (UK),pt_PT:Português (Portugal)"
 *
 * Duplicates: last-wins (env-override semantics). The code is kept as given
 * (`en_GB`), because that is what is persisted and sent to the API.
 *
 * A malformed segment (no colon, empty code or label, a code that does not
 * look like a locale) throws an `InvalidArgumentException` naming the env
 * variable and the segment. This differs from `CustomAccentsParser`, which
 * drops and logs, on purpose: the maintainer prefers a loud failure for a
 * set-but-unusable config value over a picker that silently shows the
 * wrong labels. The parsing runs where the value is read (never in the
 * config file), so a bad value cannot break the artisan commands used to
 * fix it.
 */
final class LocaleLabelsParser
{
    public const ENV = 'MARTIS_UI_LOCALE_LABELS';

    /** Shape of a locale code: `en`, `pt_PT`, `pt-BR`, `zh_Hant_TW`. */
    private const CODE_PATTERN = '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/';

    /**
     * @return array<string, string> Map of locale code to label.
     *
     * @throws InvalidArgumentException When a segment is malformed.
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $labels = [];
        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            if (! str_contains($segment, ':')) {
                throw new InvalidArgumentException(sprintf(
                    '%s: malformed segment "%s" (expected `code:label`, e.g. `en_GB:English (UK)`).',
                    self::ENV,
                    $segment,
                ));
            }

            [$code, $label] = array_map('trim', explode(':', $segment, 2));

            if ($code === '' || $label === '') {
                throw new InvalidArgumentException(sprintf(
                    '%s: segment "%s" has an empty code or label (expected `code:label`).',
                    self::ENV,
                    $segment,
                ));
            }

            if (! self::isValidCode($code)) {
                throw new InvalidArgumentException(sprintf(
                    '%s: segment "%s" has an invalid locale code "%s" (expected e.g. `en`, `en_GB`, `pt-BR`).',
                    self::ENV,
                    $segment,
                    $code,
                ));
            }

            $labels[$code] = $label;
        }

        return $labels;
    }

    /** Whether `$code` looks like a locale code (`en`, `en_GB`, `pt-BR`). */
    public static function isValidCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }
}
