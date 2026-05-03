<?php

declare(strict_types=1);

namespace Martis\Tools;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Martis\Contracts\ToolContract;

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
    protected ?string $icon = null;

    protected ?string $component = null;

    protected ?string $menuSection = null;

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

    public function withMenuSection(?string $section): static
    {
        $this->menuSection = $section;

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
     * `martis/api/tools/{uriKey}` and the default middleware is the same
     * stack the rest of the package uses (`web`, `martis.auth`).
     *
     * Skipped silently when the file does not exist — this lets a tool
     * keep the call in place even when the consumer has not yet shipped
     * a routes file.
     *
     * @param  list<string>  $middleware  Middleware stack. Defaults to the standard Martis admin stack.
     * @param  string|null  $prefix  URL prefix. Defaults to `martis/api/tools/{uriKey}`.
     */
    public function loadRoutes(
        string $path,
        array $middleware = ['web', 'martis.auth'],
        ?string $prefix = null,
    ): void {
        if (! is_file($path)) {
            return;
        }

        $effectivePrefix = $prefix ?? 'martis/api/tools/'.$this->uriKey();

        Route::middleware($middleware)
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
            'uriKey' => $this->uriKey(),
            'icon' => $this->icon(),
            'component' => $this->component(),
            'menuSection' => $this->menuSection(),
            'meta' => $this->meta(),
        ];
    }
}
