<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;

/**
 * The 2FA pass of a session: the record that the user who is signed in
 * completed the two-factor challenge.
 *
 * The pass names the person who earned it (the auth identifier of the user
 * who passed the challenge or confirmed the setup), not just "a challenge
 * was passed in this session". A bare flag survived a sign-in as another
 * user in the same browser session (session()->regenerate() and the guard's
 * login keep every attribute): a panel user who passed 2FA on their own
 * account could then post a victim's password to the sign-in route and land
 * in the victim's account with the challenge considered passed.
 *
 * Two layers keep a pass from reaching another principal. {@see holds()}
 * compares the pass with the user the guard returns, so a pass never counts
 * for anyone else, and {@see Listeners\ResetTwoFactorPass} forgets it on
 * every sign-in of the Martis guard, so the same user signing in again meets
 * the challenge again.
 */
final class TwoFactorPass
{
    /** The session key holding the auth identifier of the user who passed. */
    public const SESSION_KEY = 'martis_two_factor_passed_for';

    /**
     * The key the pass had before it named a user. A session that still
     * carries it is not trusted: it is cleared with the pass.
     */
    private const LEGACY_SESSION_KEY = 'martis_two_factor_passed';

    /** Record that the given user passed the challenge in this session. */
    public static function grant(Session $session, Authenticatable $user): void
    {
        $session->forget(self::LEGACY_SESSION_KEY);
        $session->put(self::SESSION_KEY, self::identify($user));
    }

    /** Whether this session holds a pass earned by exactly this user. */
    public static function holds(Session $session, Authenticatable $user): bool
    {
        $held = $session->get(self::SESSION_KEY);

        return is_string($held) && $held !== '' && hash_equals($held, self::identify($user));
    }

    /** Forget the pass: the next request of a user who has 2FA meets the challenge. */
    public static function revoke(Session $session): void
    {
        $session->forget([self::SESSION_KEY, self::LEGACY_SESSION_KEY]);
    }

    /** The user as the pass records them: the auth identifier, as a string. */
    private static function identify(Authenticatable $user): string
    {
        return (string) $user->getAuthIdentifier();
    }
}
