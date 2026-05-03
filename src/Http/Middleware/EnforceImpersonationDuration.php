<?php

declare(strict_types=1);

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Martis\Impersonation\ImpersonationManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-stop expired impersonation sessions.
 *
 * When `martis.impersonation.max_duration_minutes` is set to a positive
 * integer and the current request belongs to an impersonation session
 * older than that window, this middleware calls `ImpersonationManager::stop()`
 * before the request reaches the controller. The operator gets bumped
 * back into their own account; the next page render shows their normal
 * UI without the impersonation banner.
 *
 * The default is `0` (disabled), which preserves the v0.10 — v1.8.7
 * behaviour: impersonation runs until the operator clicks Stop or the
 * browser session ends.
 *
 * Registered automatically by `MartisServiceProvider::registerMiddlewareAlias()`
 * onto every protected Martis route. Consumers do not need to wire it
 * by hand.
 */
class EnforceImpersonationDuration
{
    public function __construct(
        private readonly ImpersonationManager $manager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->manager->isExpired()) {
            $this->manager->stop();
        }

        return $next($request);
    }
}
