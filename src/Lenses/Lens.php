<?php

namespace Martis\Lenses;

use Closure;
use DateInterval;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\LensContract;
use Martis\Http\Requests\LensRequest;

/**
 * Base class for Martis lenses.
 *
 * Nova v5 parity:
 * - abstract `query(LensRequest, Builder): Builder|Paginator`
 * - `fields/cards/filters/actions(Request)` return arrays
 * - `$perPageOptions`, `$polling`, `$pollingInterval`, `$showPollingToggle`
 * - `canSee(Closure)` / `canSeeWhen(ability, args)`
 *
 * Martis extensions (documented as "Martis extension"):
 * - `summary(Request, Builder): array` — sticky summary row
 * - `cacheFor(DateInterval|int $seconds)` — per-lens query cache
 * - `withDefaultFilters(array)` — opens the lens with filters pre-applied
 * - URL state sync — preserved via query params on the client
 *
 * @phpstan-consistent-constructor
 */
abstract class Lens implements LensContract
{
    /** Auto-refresh the lens view. */
    public static bool $polling = false;

    /** Auto-refresh interval in seconds. */
    public static int $pollingInterval = 5;

    /** Show a UI toggle to pause polling. */
    public static bool $showPollingToggle = false;

    /** Authorization callback — Nova v5 parity. */
    protected ?Closure $canSeeCallback = null;

    /** Optional explicit URI key; falls back to kebab-case of class basename. */
    protected ?string $uriKey = null;

    /** Optional custom frontend component key. */
    protected ?string $component = null;

    /** @var array<string, mixed> Free-form meta forwarded to the UI. */
    protected array $meta = [];

    /** @var array<string, mixed> Default filter values applied on first load (Martis extension). */
    protected array $defaultFilters = [];

    /** Cache TTL in seconds for the lens query result (0 = disabled). Martis extension. */
    protected int $cacheTtlSeconds = 0;

    public function __construct() {}

    public static function make(): static
    {
        return new static();
    }

    /**
     * Whether the concrete lens has overridden the given hook method.
     *
     * Used by the controller to decide whether to fall back to the parent
     * resource's implementation (no override) or to trust the lens's
     * return value verbatim — including an explicit empty array, which
     * means "I have none of these".
     */
    public function hasOverride(string $method): bool
    {
        try {
            $reflection = new \ReflectionMethod($this, $method);
        } catch (\ReflectionException) {
            return false;
        }

        return $reflection->getDeclaringClass()->getName() !== self::class;
    }

    // -------------------------------------------------------------------------
    // Nova v5 — abstract / overridable hooks
    // -------------------------------------------------------------------------

    /**
     * Compose the lens dataset.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>|Paginator
     */
    abstract public function query(LensRequest $request, Builder $query): Builder|Paginator;

    /**
     * Fields shown in the lens index. Defaults to no fields — override.
     *
     * @return list<\Martis\Contracts\FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }

    /**
     * Cards/metrics shown above the lens index.
     *
     * @return list<\Martis\Contracts\CardContract|\Martis\Contracts\MetricContract|object>
     */
    public function cards(Request $request): array
    {
        return [];
    }

    /**
     * Filters available inside the lens.
     *
     * @return list<\Martis\Contracts\FilterContract>
     */
    public function filters(Request $request): array
    {
        return [];
    }

    /**
     * Actions available inside the lens. Defaults to the parent resource's
     * actions — the caller merges them in when this returns an empty array.
     *
     * @return list<\Martis\Contracts\ActionContract>
     */
    public function actions(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Nova v5 — identity and authorization
    // -------------------------------------------------------------------------

    public function name(): string
    {
        $base = class_basename(static::class);
        if (Str::endsWith($base, 'Lens') && $base !== 'Lens') {
            $base = Str::beforeLast($base, 'Lens');
        }

        return (string) Str::of($base)->snake(' ')->title();
    }

    public function uriKey(): string
    {
        if ($this->uriKey !== null) {
            return $this->uriKey;
        }

        $base = class_basename(static::class);
        if (Str::endsWith($base, 'Lens') && $base !== 'Lens') {
            $base = Str::beforeLast($base, 'Lens');
        }

        return Str::kebab($base);
    }

    public function component(): ?string
    {
        return $this->component;
    }

    public function componentKey(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /**
     * Nova v5 shorthand: `$lens->canSeeWhen('viewAny', User::class)`.
     * Delegates to the current user's Gate::check.
     */
    public function canSeeWhen(string $ability, mixed $arguments = null): static
    {
        $this->canSeeCallback = function (Request $request) use ($ability, $arguments): bool {
            $user = $request->user();
            if ($user === null) {
                return false;
            }

            return $user->can($ability, $arguments);
        };

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
    // Nova v5 — pagination & polling accessors
    // -------------------------------------------------------------------------

    /**
     * Per-page selector options. Override in the concrete lens to customise.
     * Signature matches `Resource::perPageOptions()` so the two are
     * interchangeable.
     *
     * When the concrete lens does not override this method, the controller
     * inherits the parent resource's `perPageOptions()` (see `hasOverride`).
     *
     * @return array<int, int>
     */
    public static function perPageOptions(): array
    {
        return [10, 25, 50, 100];
    }

    /**
     * Default per-page value. Override in the concrete lens to customise.
     * Signature matches `Resource::perPage()`.
     *
     * When the concrete lens does not override this method, the controller
     * inherits the parent resource's `perPage()` (see `hasOverride`).
     */
    public static function perPage(): int
    {
        // Use the package config when the framework is booted, otherwise
        // fall back to the same hard-coded default the Resource uses so
        // unit tests running outside the Laravel container keep working.
        try {
            return (int) config('martis.pagination.default_per_page', 25);
        } catch (\Throwable) {
            return 25;
        }
    }

    public function pollingEnabled(): bool
    {
        return static::$polling;
    }

    public function pollingInterval(): int
    {
        return static::$pollingInterval;
    }

    public function pollingToggleVisible(): bool
    {
        return static::$showPollingToggle;
    }

    // -------------------------------------------------------------------------
    // Martis extensions
    // -------------------------------------------------------------------------

    /**
     * Martis extension — aggregated summary row rendered sticky at the bottom
     * of the lens table. Return an assoc array of `[key => ['label' => ..., 'value' => ...]]`.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, array{label: string, value: mixed, format?: string}>
     */
    public function summary(Request $request, Builder $query): array
    {
        return [];
    }

    /**
     * Martis extension — declarative query cache. Pass a DateInterval or
     * a raw integer of seconds. The cache key mixes lens uriKey, active
     * filters, search, sort and page so semantically distinct views don't
     * share entries.
     */
    public function cacheFor(DateInterval|int $ttl): static
    {
        if ($ttl instanceof DateInterval) {
            $reference = new \DateTimeImmutable();
            $seconds = $reference->add($ttl)->getTimestamp() - $reference->getTimestamp();
            $this->cacheTtlSeconds = max(0, $seconds);
        } else {
            $this->cacheTtlSeconds = max(0, (int) $ttl);
        }

        return $this;
    }

    public function cacheTtl(): int
    {
        return $this->cacheTtlSeconds;
    }

    /**
     * Martis extension — seed the lens with pre-applied filters. The user
     * can clear them; once cleared, the empty state is respected (the
     * defaults do not re-populate).
     *
     * @param  array<string, mixed>  $defaults  Keyed by filter uriKey.
     */
    public function withDefaultFilters(array $defaults): static
    {
        $this->defaultFilters = $defaults;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultFilters(): array
    {
        return $this->defaultFilters;
    }

    // -------------------------------------------------------------------------
    // Meta forwarding
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

    /**
     * Serialize the lens descriptor for the schema payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'lens',
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'perPageOptions' => static::perPageOptions(),
            'perPage' => static::perPage(),
            'polling' => $this->pollingEnabled(),
            'pollingInterval' => $this->pollingInterval(),
            'showPollingToggle' => $this->pollingToggleVisible(),
            'defaultFilters' => $this->defaultFilters(),
            'cacheTtlSeconds' => $this->cacheTtl(),
            'meta' => $this->meta(),
        ];
    }
}
