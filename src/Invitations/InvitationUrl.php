<?php

namespace Martis\Invitations;

/**
 * Static, overridable seam that builds the URL an invitation
 * notification (Task 10) puts in the invite email/notification. Mirrors
 * the idiom of Laravel's own
 * `Illuminate\Auth\Notifications\ResetPassword::createUrlUsing()`: a
 * package default that any consumer can replace wholesale from their
 * own service provider — e.g. to point invite links at an off-platform
 * signup page, or to append extra query parameters.
 *
 * `MartisServiceProvider::registerInvitationAcceptUrl()` seeds the
 * default (`route('martis.invitations.accept', $rawToken)`) at boot
 * time, but only when no consumer has already registered their own
 * callback — and `url()` below falls back to the same route builder
 * even if that registration never ran, so this class is safe to call
 * standalone (e.g. from tests) without booting the full provider.
 */
class InvitationUrl
{
    /** @var (callable(Invitation, string): string)|null */
    protected static $createUrlCallback;

    /**
     * Replace the default URL builder. Pass `null` to reset to the
     * package default (`route('martis.invitations.accept', $rawToken)`).
     *
     * @param  (callable(Invitation, string): string)|null  $callback
     */
    public static function createUrlUsing(?callable $callback): void
    {
        static::$createUrlCallback = $callback;
    }

    /** Whether a callback (consumer's or the provider's own default) is registered. */
    public static function hasCustomCallback(): bool
    {
        return static::$createUrlCallback !== null;
    }

    /** Build the accept URL for `$invitation`, given its raw (unhashed) token. */
    public static function url(Invitation $invitation, string $rawToken): string
    {
        if (static::$createUrlCallback !== null) {
            return call_user_func(static::$createUrlCallback, $invitation, $rawToken);
        }

        return route('martis.invitations.accept', $rawToken);
    }
}
