<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The resources that have no policy (v2.4.0).
 *
 * A resource without a policy lets every user the panel admits view, create,
 * update and delete its records, as in Nova. That is how a prototype starts
 * and a gap in production, so a resource found without a policy outside the
 * environments the panel is open in (`martis.panel_access.open_environments`)
 * is logged: once per resource per day, never on every request.
 */
final class PolicyCoverage
{
    /** @var array<string, true> The resources this process has already looked at. */
    private static array $noted = [];

    /** Note that $resource has no policy: a warning, once a day, outside the open environments. */
    public static function noteMissing(string $resource): void
    {
        if (isset(self::$noted[$resource])) {
            return;
        }

        self::$noted[$resource] = true;

        // A resource run without a Laravel application (a bare container) has no environment.
        $app = app();
        if (! $app instanceof Application || PanelAccess::environmentIsOpen()) {
            return;
        }

        try {
            if (! Cache::add('martis.policy-missing.'.sha1($resource), true, now()->addDay())) {
                return;
            }

            Log::warning(sprintf(
                'Martis: the resource [%s] has no policy, so every user the panel admits can view, create, update and delete its records. '
                .'Add a policy (php artisan martis:policy), register one for its model, or set its $policy. See docs/authorization.md.',
                $resource,
            ), ['resource' => $resource, 'environment' => $app->environment()]);
        } catch (Throwable) {
            // A cache or log that is down must not break the request.
        }
    }

    /** Forget what this process looked at. For tests. */
    public static function flush(): void
    {
        self::$noted = [];
    }
}
