<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who may open the panel at all: the `viewMartis` gate (v2.1.0), closed by
 * default outside the local environments (v2.4.0).
 *
 * An app that defines the gate decides per user, in every environment. An
 * app that does not define it lets every user the Martis guard signs in open
 * the panel only in the environments of `martis.panel_access.open_environments`
 * (`local` and `testing` by default), as Nova's `viewNova` does with `local`:
 * anywhere else the panel stays shut until the gate is defined, so a guard
 * that also signs in customers or other non-staff users never opens the
 * admin to them by omission. Before v2.4.0 an undefined gate was open in
 * every environment.
 *
 * The user is never passed as a gate argument, so a policy of the user
 * model cannot intercept the check (laravel/nova-issues#5909);
 * `Gate::before()` callbacks apply as usual.
 */
final class PanelAccess
{
    public const GATE = 'viewMartis';

    /** The environments `martis.panel_access.open_environments` names when it is not set. */
    private const DEFAULT_OPEN_ENVIRONMENTS = ['local', 'testing'];

    /** Whether the refusal for a missing gate was logged by this process. */
    private static bool $warned = false;

    public static function allows(?Authenticatable $user): bool
    {
        if (! Gate::has(self::GATE)) {
            if (self::environmentIsOpen()) {
                return true;
            }

            self::warnGateIsMissing();

            return false;
        }

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->check(self::GATE);
    }

    /**
     * Whether the panel guards run at all: the app defines the gate, or the
     * environment is not one the panel is open in. Both make the
     * `martis.authorize` middleware refuse somebody.
     */
    public static function enforced(): bool
    {
        return Gate::has(self::GATE) || ! self::environmentIsOpen();
    }

    /**
     * Whether the environment is one the panel is open in while the app
     * defines no gate (`martis.panel_access.open_environments`).
     */
    public static function environmentIsOpen(): bool
    {
        return app()->environment(self::openEnvironments());
    }

    /**
     * The environments of `martis.panel_access.open_environments`: a list or
     * a comma-separated string, `local` and `testing` when unset. An empty
     * list opens none.
     *
     * @return list<string>
     */
    public static function openEnvironments(): array
    {
        $configured = config('martis.panel_access.open_environments', self::DEFAULT_OPEN_ENVIRONMENTS);
        $names = is_string($configured) ? explode(',', $configured) : (is_array($configured) ? $configured : self::DEFAULT_OPEN_ENVIRONMENTS);

        return array_values(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? trim($name) : '', $names),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Say once a day, not on every request, why the panel refused a user
     * that nothing else refuses: the one fix is in the app.
     */
    private static function warnGateIsMissing(): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;

        try {
            if (! Cache::add('martis.panel-access.gate-missing', true, now()->addDay())) {
                return;
            }

            Log::warning(sprintf(
                'Martis: the panel refused a signed-in user because the app defines no `%s` gate and the environment [%s] is not one of martis.panel_access.open_environments [%s]. '
                .'Define the gate in app/Providers/MartisServiceProvider.php (see docs/authorization.md, "Panel access"), '
                .'or list the environment in martis.panel_access.open_environments (MARTIS_PANEL_OPEN_ENVIRONMENTS).',
                self::GATE,
                app()->environment(),
                implode(', ', self::openEnvironments()),
            ));
        } catch (Throwable) {
            // A cache or log that is down must not turn a refusal into an error.
        }
    }

    /** Forget what this process logged. For tests. */
    public static function flushWarnings(): void
    {
        self::$warned = false;
    }
}
