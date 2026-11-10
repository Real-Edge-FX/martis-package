<?php

namespace Martis\Dashboards;

use Illuminate\Http\Request;
use Martis\Auth\PanelAccess;
use Martis\Cache\MartisCache;
use Martis\Contracts\DashboardContract;
use Martis\Gates\SoftGate;
use Martis\MartisManager;

/**
 * The dashboards a request may see, as `GET /api/dashboards` answers them.
 *
 * The panel shell also embeds this list in its boot payload
 * (`window.MartisConfig.dashboards`, v2.19.0), so the SPA knows the current
 * dashboard, the sidebar's DASHBOARDS group and the tab strip on its first
 * render instead of drawing placeholder labels until the request answers.
 * Both read this one builder, so the seed and the API can never disagree.
 */
class DashboardList
{
    public function __construct(
        private readonly MartisManager $manager,
        private readonly MartisCache $cache,
    ) {}

    /** Request attribute holding the list built for the shell. */
    private const SHELL_ATTRIBUTE = 'martis.shell_dashboards';

    /**
     * The list for the panel shell (boot payload and `<title>`), or null
     * when the page is served to someone the API would refuse it to.
     *
     * The shell's catch-all route runs the API's stack, but that stack lets
     * `GET {path}/2fa/challenge` through for a user with a pending
     * challenge, one `viewMartis` refuses, and one held by the forced
     * password change, so the challenge page can render; and the panel's
     * 403 page renders the shell for a refused user. None of them may see
     * the list (`GET /api/dashboards` answers 423, 403 or 409), so the
     * shell carries none on the challenge page, for a refused user, or
     * before sign-in.
     *
     * Built once per request: the boot payload and the title share it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function forShell(Request $request): ?array
    {
        if ($request->attributes->has(self::SHELL_ATTRIBUTE)) {
            /** @var list<array<string, mixed>>|null */
            return $request->attributes->get(self::SHELL_ATTRIBUTE);
        }

        $list = $this->mayServeShell($request) ? $this->payload($request) : null;
        $request->attributes->set(self::SHELL_ATTRIBUTE, $list);

        return $list;
    }

    private function mayServeShell(Request $request): bool
    {
        $user = $request->user();
        if ($user === null || ! PanelAccess::allows($user)) {
            return false;
        }

        $basePath = trim((string) config('martis.path', 'martis'), '/');

        return ! $request->is(($basePath !== '' ? $basePath.'/' : '').'2fa/challenge');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payload(Request $request): array
    {
        // Resolve dashboards outside the cache so the lock predicate is
        // evaluated on every request: a lock frozen in the cached payload
        // would hide a plan downgrade/upgrade until the TTL expires (the same
        // reason MetricController::show() bypasses the cache for the lock).
        $instances = $this->manager->resolveDashboards($request);

        // Cache the structural shape (name, uriKey, layout, badge, icon, …)
        // with the lock field stripped. Lock is re-injected below from the
        // live predicate evaluation.
        //
        // The key carries a fingerprint of the set resolved for this user
        // (class + uriKey of every dashboard authorizedToSee let through),
        // so the entry can never outlive its own authorization snapshot: a
        // dashboard registered after the list was cached, one removed, or a
        // user whose state changes what authorizedToSee() answers all land
        // on a fresh key immediately. The finite layer TTL bounds the
        // orphaned entries and lets the cached shape itself converge.
        $userKey = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
        // The lock state of each dashboard is part of it: a locked dashboard's
        // `meta` is left out of the payload, so a plan change lands at once.
        $fingerprint = substr(sha1(implode('|', array_map(
            fn (DashboardContract $d): string => $d::class.'@'.$d->uriKey(),
            $instances,
        )).'#'.SoftGate::fingerprint($instances, $request)), 0, 16);

        /** @var list<array<string, mixed>> $cached */
        $cached = $this->cache->remember('dashboards', 'list:'.$userKey.':'.app()->getLocale().':'.$fingerprint, function () use ($instances): array {
            return array_map(function (DashboardContract $d): array {
                $arr = $d->toArray();
                // Strip the lock key so stale lock state is never persisted.
                $arr['lock'] = null;

                return $arr;
            }, $instances);
        });

        // Re-inject the live lock payload for each dashboard entry. This
        // mirrors the guard in MetricController::show(): evaluate
        // lockPayloadFor() outside the cache boundary so plan/role changes
        // land immediately.
        return array_map(function (array $entry) use ($instances, $request): array {
            foreach ($instances as $instance) {
                if ($instance->uriKey() === $entry['uriKey']) {
                    $entry['lock'] = method_exists($instance, 'lockPayloadFor')
                        ? $instance->lockPayloadFor($request)
                        : null;
                    break;
                }
            }

            return $entry;
        }, $cached);
    }
}
