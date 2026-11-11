<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The switch of email and password sign-in (`martis.auth.password_login`,
 * v2.21.0), for a panel whose people sign in through SSO only.
 *
 * Off, `POST /login` and `POST /api/auth/login` refuse every address with a
 * 403 (EnsurePasswordLoginAllowed) except the `break_glass_emails`, and the
 * password reset flow is off with it: a reset link would hand a password
 * sign-in back through the inbox.
 */
final class PasswordLogin
{
    public const ENABLED_KEY = 'martis.auth.password_login.enabled';

    public const BREAK_GLASS_KEY = 'martis.auth.password_login.break_glass_emails';

    /** Cache key the boot warning is rate limited with. */
    public const WARNING_KEY = 'martis:password-login-locked-out-warning';

    public static function enabled(): bool
    {
        $value = config(self::ENABLED_KEY, true);

        if (is_bool($value)) {
            return $value;
        }

        $parsed = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($parsed === null) {
            throw AuthConfigurationException::forKey(self::ENABLED_KEY, 'must be true or false.');
        }

        return $parsed;
    }

    /**
     * The password reset flow as the panel sees it: the app's switch
     * (`martis.auth.passwordReset.enabled`) and password sign-in on.
     */
    public static function passwordResetEnabled(): bool
    {
        return (bool) config('martis.auth.passwordReset.enabled', false) && self::enabled();
    }

    /**
     * Whether the address may use the password form while it is off.
     */
    public static function isBreakGlass(mixed $email): bool
    {
        if (! is_string($email)) {
            return false;
        }

        $email = self::normalise($email);

        return $email !== '' && in_array($email, self::breakGlassEmails(), true);
    }

    /**
     * @return list<string> The addresses, trimmed and lower-cased.
     */
    public static function breakGlassEmails(): array
    {
        $value = config(self::BREAK_GLASS_KEY, []);

        if (! is_array($value)) {
            throw AuthConfigurationException::forKey(self::BREAK_GLASS_KEY, 'must be an array of email addresses.');
        }

        $emails = [];
        foreach ($value as $email) {
            if (! is_string($email)) {
                throw AuthConfigurationException::forKey(self::BREAK_GLASS_KEY, 'must hold strings only.');
            }

            $email = self::normalise($email);
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Log one warning (a day at most) when password sign-in is off and
     * nothing else lets a person in: no enabled SSO provider and magic-link
     * sign-in off. Only the break-glass addresses could still sign in.
     *
     * Called on every boot, so the cheap config checks run first and the
     * cache is touched only when the warning is due. Nothing here may break
     * the boot: a failing cache or config read is swallowed.
     */
    public static function warnWhenLockedOut(): void
    {
        try {
            if (self::enabled() || self::hasOtherWayIn()) {
                return;
            }

            if (! Cache::add(self::WARNING_KEY, true, now()->addDay())) {
                return;
            }

            Log::warning(
                'Martis: password sign-in is off ('.self::ENABLED_KEY.' is false) and nothing else lets people in: '
                .'no provider of martis.auth.sso.providers is enabled with martis.auth.sso.enabled, and '
                .'martis.auth.magic_link.enabled is false. Only '.self::BREAK_GLASS_KEY.' can sign in.'
            );
        } catch (\Throwable) {
            // Never break the boot over a warning.
        }
    }

    private static function hasOtherWayIn(): bool
    {
        if ((bool) config('martis.auth.magic_link.enabled', false)) {
            return true;
        }

        if (! (bool) config('martis.auth.sso.enabled', false)) {
            return false;
        }

        $providers = config('martis.auth.sso.providers', []);

        foreach (is_array($providers) ? $providers : [] as $provider) {
            if (is_array($provider) && (bool) ($provider['enabled'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The form an address is compared and looked up in: trimmed and
     * lower-cased. The middleware hands it to the controller, so the account
     * signed in is the one the list names.
     */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
