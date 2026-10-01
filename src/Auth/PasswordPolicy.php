<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The password policy of every Martis surface that sets a password: the
 * app's `Password::defaults()`, read through `Password::default()` as Nova 5
 * and Fortify read it, else Laravel's own `Password::min(8)`.
 *
 * Profile, registration, password reset, invitation accept, the forced
 * password change and `martis:user` validate with {@see self::rule()}. The
 * SPA draws its password checklist from {@see self::requirements()}, which
 * the Blade shell exposes as `window.MartisConfig.auth.passwordRequirements`.
 */
final class PasswordPolicy
{
    /** The rule a new password must pass. */
    public static function rule(): Rule
    {
        return Password::default();
    }

    /**
     * The rule's requirements in the shape the SPA's password checklist
     * reads, or null when the app's default is a rule other than
     * `Illuminate\Validation\Rules\Password`, which the SPA cannot read. The
     * custom rules of a `Password` (`->rules([...])`) stay server-side.
     *
     * @return array{minLength?: int, maxLength?: int, uppercase?: true, lowercase?: true, letters?: true, number?: true, symbol?: true, uncompromised?: true}|null
     */
    public static function requirements(): ?array
    {
        $rule = self::rule();

        if (! $rule instanceof Password) {
            return null;
        }

        $applied = $rule->appliedRules();
        $requirements = [];

        if ((int) $applied['min'] > 0) {
            $requirements['minLength'] = (int) $applied['min'];
        }
        if ($applied['max'] !== null) {
            $requirements['maxLength'] = (int) $applied['max'];
        }
        if ($applied['mixedCase']) {
            $requirements['uppercase'] = true;
            $requirements['lowercase'] = true;
        }
        if ($applied['letters']) {
            $requirements['letters'] = true;
        }
        if ($applied['numbers']) {
            $requirements['number'] = true;
        }
        if ($applied['symbols']) {
            $requirements['symbol'] = true;
        }
        if ($applied['uncompromised']) {
            $requirements['uncompromised'] = true;
        }

        return $requirements;
    }
}
