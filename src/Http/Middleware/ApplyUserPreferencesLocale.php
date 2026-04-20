<?php

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Preferences\PreferencesResolver;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Apply the authenticated user's locale preference to Laravel's runtime
 * locale so `__()`, validation messages, and JSON responses return text
 * in the user's chosen language — matching the frontend i18n resources
 * loaded on the same request.
 *
 * Resolution mirrors PreferencesResolver: URL preset > user row > config
 * default. Tolerant of missing tables / unauthenticated requests.
 */
class ApplyUserPreferencesLocale
{
    public function __construct(private readonly PreferencesResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $prefs = $this->resolver->resolve($request);
            $locale = is_string($prefs['locale'] ?? null) ? $prefs['locale'] : null;
            if ($locale !== null && $locale !== '') {
                app()->setLocale($locale);
            }
        } catch (Throwable) {
            // Preferences unavailable (no DB, no user) — keep the app default locale.
        }

        return $next($request);
    }
}
