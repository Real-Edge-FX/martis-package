<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Martis\Contracts\MustChangePassword;
use Martis\Impersonation\ImpersonationManager;

/**
 * Who the forced password change gate holds (`martis.auth.password_change`,
 * EnsurePasswordIsChanged), and how a user leaves it.
 */
final class PasswordChangeRequirement
{
    /** The session key the SSO callback sets (SsoController). */
    private const SSO_SESSION_KEY = 'martis_sso_provider';

    public static function enabled(): bool
    {
        return (bool) config('martis.auth.password_change.enabled', false);
    }

    /**
     * Whether the app flags the user: the MustChangePassword contract, else
     * the configured boolean column when the user's loaded attributes hold
     * it. A user with neither is never flagged, so a missing column cannot
     * lock every user out.
     */
    public static function flagged(?Authenticatable $user): bool
    {
        if (! self::enabled() || $user === null) {
            return false;
        }

        if ($user instanceof MustChangePassword) {
            return $user->mustChangePassword();
        }

        $column = self::column();

        return $user instanceof Model
            && array_key_exists($column, $user->getAttributes())
            && (bool) $user->getAttribute($column);
    }

    /**
     * Whether the gate holds the request's user: flagged, in a session that
     * is neither an impersonation (the operator must never choose the user's
     * password) nor opened through SSO (the user knows no password).
     */
    public static function requiredFor(Request $request, ?Authenticatable $user): bool
    {
        if (! self::flagged($user)) {
            return false;
        }

        if (app(ImpersonationManager::class)->isActive()) {
            return false;
        }

        return ! ($request->hasSession() && $request->session()->has(self::SSO_SESSION_KEY));
    }

    /**
     * Record on the user that they chose a new password, without saving: the
     * contract's markPasswordChanged(), else the column set to false on a
     * model that holds it. Nothing while the gate is disabled.
     */
    public static function markChanged(Authenticatable $user): void
    {
        if (! self::enabled()) {
            return;
        }

        if ($user instanceof MustChangePassword) {
            $user->markPasswordChanged();

            return;
        }

        $column = self::column();

        if ($user instanceof Model && array_key_exists($column, $user->getAttributes())) {
            $user->forceFill([$column => false]);
        }
    }

    /** Where a held page request goes: the configured url, else the Martis page. */
    public static function pageUrl(): string
    {
        $configured = config('martis.auth.password_change.url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $path = trim((string) config('martis.path', 'martis'), '/');

        return '/'.($path !== '' ? $path.'/' : '').'password/change';
    }

    private static function column(): string
    {
        $column = config('martis.auth.password_change.column', 'must_change_password');

        if (! is_string($column) || $column === '') {
            throw new InvalidArgumentException(sprintf(
                'The [martis.auth.password_change.column] config value (MARTIS_AUTH_PASSWORD_CHANGE_COLUMN) must name a column of the users table, got %s.',
                is_string($column) ? 'an empty string' : get_debug_type($column),
            ));
        }

        return $column;
    }
}
