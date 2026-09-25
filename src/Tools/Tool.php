<?php

declare(strict_types=1);

namespace Martis\Tools;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Martis\Concerns\HasBadge;
use Martis\Concerns\HasGate;
use Martis\Concerns\HasPolicy;
use Martis\Contracts\ToolContract;
use Martis\Http\RouteMiddleware;

/**
 * Base class for Martis Tools — free-form sidebar pages that are not
 * resources, dashboards, or lenses.
 *
 * Subclass and override the hooks the consumer cares about:
 *
 * ```php
 * class SystemStatus extends Tool
 * {
 *     public function __construct()
 *     {
 *         parent::__construct(
 *             name: __('System Status'),
 *             uriKey: 'system-status',
 *         );
 *     }
 *
 *     public function icon(): ?string
 *     {
 *         return 'pulse';
 *     }
 *
 *     public function component(): ?string
 *     {
 *         return 'tool:system-status';
 *     }
 *
 *     public function menuSection(): ?string
 *     {
 *         return __('Operations');
 *     }
 * }
 * ```
 *
 * Register tools from a service provider:
 *
 * ```php
 * Martis::tools([
 *     new App\Martis\Tools\SystemStatus(),
 *     SystemBackups::class, // class-string also accepted
 * ]);
 * ```
 *
 * @phpstan-consistent-constructor
 */
class Tool implements ToolContract
{
    use HasBadge;
    use HasGate;
    use HasPolicy;

    protected ?string $icon = null;

    protected ?string $component = null;

    protected ?string $menuSection = null;

    /** Opt-in for the bundled "System" sidebar section (v1.35.0+). */
    protected bool $systemSection = false;

    /** Position inside the bundled "System" section (v1.38.0+). */
    protected int $systemSectionOrder = 100;

    /**
     * Optional breadcrumb label override. When set, the React shell shows
     * this label as the deepest crumb instead of `name()`. Defaults to
     * null (the breadcrumb tracks `name()`).
     */
    protected ?string $breadcrumb = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    /** @var Closure(Request): bool|null */
    protected ?Closure $canSeeCallback = null;

    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
    ) {}

    public static function make(string $name, ?string $uriKey = null): static
    {
        return new static($name, $uriKey);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uriKey(): string
    {
        return $this->uriKey ?? Str::kebab($this->name);
    }

    // -------------------------------------------------------------------------
    // Visual hooks
    // -------------------------------------------------------------------------

    public function icon(): ?string
    {
        return $this->icon;
    }

    /**
     * Set the Phosphor icon for this tool. Returns the same instance
     * so the call can chain in registration arrays.
     */
    public function withIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function component(): ?string
    {
        return $this->component;
    }

    /**
     * Bind this tool to a React component key. The frontend looks up
     * the key in `componentRegistry` when the user navigates to
     * `/martis/tools/{uriKey}`.
     */
    public function withComponent(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    public function menuSection(): ?string
    {
        return $this->menuSection;
    }

    /**
     * Optional sidebar section label. Ignored once the tool opts into the
     * bundled "System" section via `withSystemSection()`.
     */
    public function withMenuSection(?string $section): static
    {
        $this->menuSection = $section;

        return $this;
    }

    public function belongsToSystemSection(): bool
    {
        return $this->systemSection;
    }

    public function systemSectionOrder(): int
    {
        return $this->systemSectionOrder;
    }

    /**
     * Place this tool inside the bundled "System" sidebar section, next to
     * the audit log, the System-section resources and the Cache admin
     * link, instead of a section of its own. Takes precedence over
     * `withMenuSection()`. Mirrors `Resource::belongsToSystemSection()`;
     * subclasses may override the getter instead of calling the setter.
     *
     * `$order` positions the tool inside the section (see
     * `systemSectionOrder()`); `null` keeps the current weight (100 unless
     * set before), so a tool that sets nothing keeps the default order:
     * after the System-section resources, before the Cache admin link.
     */
    public function withSystemSection(bool $value = true, ?int $order = null): static
    {
        $this->systemSection = $value;

        if ($order !== null) {
            $this->systemSectionOrder = $order;
        }

        return $this;
    }

    /**
     * Whether the sidebar shows a numeric count badge next to this Tool,
     * mirroring the Resource contract. Defaults to true; the badge only
     * appears when menuCount() also returns a non-null value.
     */
    public function showMenuCount(): bool
    {
        return true;
    }

    /**
     * Compute the sidebar count badge for this Tool (null hides it). Defaults
     * to null — a full-canvas Tool that owns list data overrides this to
     * return its own count, scoped/authorised however it likes. The value is
     * serialised on the nav item and refreshed by the /api/navigation/badges
     * poll keyed by uriKey, exactly like a Resource count.
     */
    public function menuCount(Request $request): ?int
    {
        return null;
    }

    /**
     * Override-friendly accessor. Subclasses can return a per-request
     * value (e.g. `__('edgeflow.tools.charts.breadcrumb')`) the same way
     * they override `name()`. Return `null` to fall back to `name()`.
     */
    public function breadcrumb(): ?string
    {
        return $this->breadcrumb;
    }

    /**
     * Override the breadcrumb label without changing the page heading,
     * sidebar entry, or `document.title` (those keep reading `name()`).
     * Pass `null` to clear the override and fall back to `name()`.
     */
    public function withBreadcrumb(?string $breadcrumb): static
    {
        $this->breadcrumb = $breadcrumb;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    public function authorizedToSee(Request $request): bool
    {
        // v1.11.0+: same precedence as Dashboard — Policy class wins
        // over the canSee closure when one is configured.
        $policyResult = $this->checkHasPolicyAbility('view', $request);
        if ($policyResult !== null) {
            return $policyResult;
        }

        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) call_user_func($this->canSeeCallback, $request);
    }

    // -------------------------------------------------------------------------
    // Lifecycle (boot hook for Composer-distributed tools)
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function boot(): void
    {
        // No-op by default.
    }

    // -------------------------------------------------------------------------
    // Asset publishing helpers
    // -------------------------------------------------------------------------

    /**
     * Publish files from the tool to the host application.
     *
     * Mirrors `ServiceProvider::publishes()` so tool authors can ship
     * config / migrations / public assets / language files without
     * having to subclass `ToolServiceProvider`. The actual
     * `vendor:publish` integration is done by registering an anonymous
     * provider per tool — Laravel's publish system keys publishables
     * by provider class, so each tool gets its own slot.
     *
     * Call from `boot()`:
     *
     *     public function boot(): void
     *     {
     *         $this->publishes([
     *             __DIR__.'/../config/my-tool.php' => config_path('my-tool.php'),
     *         ], 'my-tool-config');
     *     }
     *
     * @param  array<string, string>  $paths  Map of `from => to` paths.
     */
    public function publishes(array $paths, ?string $tag = null): void
    {
        if (! function_exists('app') || ! app()->bound('files')) {
            return;
        }

        $providerClass = static::class;

        // Use the standard ServiceProvider static buckets — Laravel
        // reads from these in `vendor:publish`. Append rather than
        // replace so multiple `publishes()` calls within the same
        // tool's boot() compose cleanly.
        $existingForProvider = ServiceProvider::$publishes[$providerClass] ?? [];
        ServiceProvider::$publishes[$providerClass] = array_merge($existingForProvider, $paths);

        if ($tag !== null) {
            $existingForTag = ServiceProvider::$publishGroups[$tag] ?? [];
            ServiceProvider::$publishGroups[$tag] = array_merge($existingForTag, $paths);
        }
    }

    /**
     * Convenience for publishing a tool's compiled JS / CSS bundle to
     * `public/vendor/martis-tools/{uriKey}/...` so the SPA can lazy-load
     * it. Tools that bind a custom React component via `withComponent()`
     * typically pair this with a `boot()` call.
     */
    public function publishesAssets(string $sourceDir, ?string $tag = null): void
    {
        $tag = $tag ?? 'martis-tool-'.$this->uriKey().'-assets';
        $target = function_exists('public_path')
            ? public_path('vendor/martis-tools/'.$this->uriKey())
            : null;

        if ($target === null) {
            return;
        }

        $this->publishes([$sourceDir => $target], $tag);
    }

    // -------------------------------------------------------------------------
    // Route helpers
    // -------------------------------------------------------------------------

    /**
     * The middleware of this tool's routes: the stack every protected
     * Martis API route runs (`RouteMiddleware::api()`: `martis.middleware`,
     * `martis.auth_middleware`, the impersonation expiry, the 2FA
     * challenge, the user's locale, email verification and the API
     * throttle), then `martis.tool:{uriKey}`, which answers 404 to a user
     * this tool is hidden from (`authorizedToSee()`), as the tool's page
     * does. Nova guards a tool's routes with the tool's `Authorize`
     * middleware the same way, answering 403 instead.
     *
     * `loadRoutes()` applies it by default. A route group registered in
     * `boot()` takes it too:
     *
     *     Route::middleware($this->routeMiddleware())
     *         ->prefix('martis/api/tools/'.$this->uriKey())
     *         ->group(function () { ... });
     *
     * @return list<string>
     */
    public function routeMiddleware(): array
    {
        return [...RouteMiddleware::api(), 'martis.tool:'.$this->uriKey()];
    }

    /**
     * Load a routes file under the standard Martis tool prefix and
     * middleware stack. Pair with `boot()` so consumers can ship a
     * sibling `routes/tool.php` and keep their lifecycle file lean:
     *
     *     public function boot(): void
     *     {
     *         $this->loadRoutes(__DIR__.'/../routes/tool.php');
     *     }
     *
     * The file is `require`d inside a `Route::middleware([...])->prefix(...)`
     * group, so the routes inside it should be plain `Route::post(...)` /
     * `Route::get(...)` calls without any wrapper. The default prefix is
     * `martis/api/tools/{uriKey}` and the default middleware is
     * `routeMiddleware()`: the stack of the protected API routes, then the
     * tool's own gate. A `$middleware` list is used exactly as given,
     * in place of that stack (before v2.0 the default was
     * `['web', 'martis.auth']`, which skipped the 2FA challenge, email
     * verification, the locale, the impersonation expiry and the throttle).
     *
     * Skipped silently when the file does not exist — this lets a tool
     * keep the call in place even when the consumer has not yet shipped
     * a routes file.
     *
     * @param  list<string>|null  $middleware  Middleware stack. Defaults to `routeMiddleware()`.
     * @param  string|null  $prefix  URL prefix. Defaults to `martis/api/tools/{uriKey}`.
     */
    public function loadRoutes(
        string $path,
        ?array $middleware = null,
        ?string $prefix = null,
    ): void {
        if (! is_file($path)) {
            return;
        }

        $effectivePrefix = $prefix ?? 'martis/api/tools/'.$this->uriKey();

        Route::middleware($middleware ?? $this->routeMiddleware())
            ->prefix($effectivePrefix)
            ->group($path);
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return [
            'type' => 'tool',
            'name' => $this->name(),
            'breadcrumb' => $this->breadcrumb(),
            'uriKey' => $this->uriKey(),
            'icon' => $this->icon(),
            'component' => $this->component(),
            'menuSection' => $this->menuSection(),
            'belongsToSystemSection' => $this->belongsToSystemSection(),
            'badge' => $this->badge(),
            'lock' => $this->lockPayloadNow(),
            'meta' => $this->meta(),
        ];
    }
}
