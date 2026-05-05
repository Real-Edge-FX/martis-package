<?php

namespace Martis\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Concerns\HasBadge;
use Martis\Concerns\HasGate;
use Martis\Contracts\FilterContract;
use Martis\Enums\FilterType;

/**
 * Base class for all Martis filters.
 *
 * Filters modify the resource index query based on user-selected values.
 * Subclasses must implement apply() and filterType().
 *
 * Martis extensions:
 * - canSee() authorization callback (consistent with Field and Action APIs)
 * - Default values correctly applied on frontend initial load
 *
 * @phpstan-consistent-constructor
 */
abstract class Filter implements FilterContract
{
    use HasBadge;
    use HasGate;

    /** @var array<string, mixed> */
    protected array $meta = [];

    protected ?string $component = null;

    /** Grid span in 12-column system (default: auto). Martis extension. */
    protected ?int $span = null;

    /** Authorization callback — Martis extension. */
    protected ?Closure $canSeeCallback = null;

    /**
     * Martis extension — when true, this filter is NOT inherited by
     * lenses that rely on Resource::filters(). Allows a Resource to
     * keep a filter on the default index while excluding it from lenses.
     */
    protected bool $excludeFromLens = false;

    public function __construct(
        protected string $name,
        protected ?string $uriKey = null,
    ) {}

    public static function make(string $name, ?string $uriKey = null): static
    {
        return new static($name, $uriKey);
    }

    /**
     * Apply the filter to the given query.
     *
     * @param  Builder<Model>  $query
     */
    abstract public function apply(Request $request, Builder $query, mixed $value): Builder;

    /**
     * The filter type identifier sent to the frontend.
     */
    abstract public function filterType(): FilterType;

    /** {@inheritdoc} */
    public function name(): string
    {
        return $this->name;
    }

    /** {@inheritdoc} */
    public function uriKey(): string
    {
        return $this->uriKey ?? Str::kebab($this->name);
    }

    /** {@inheritdoc} */
    public function component(): ?string
    {
        return $this->component;
    }

    /**
     * Set a custom frontend component key.
     */
    public function componentKey(string $component): static
    {
        $this->component = $component;

        return $this;
    }

    /**
     * Set the filter width in a 12-column grid.
     *
     * Martis extension: controls how much horizontal space the filter
     * occupies in the filter panel. Default is auto (flex-1).
     *
     * Common values: 3 (quarter), 4 (third), 6 (half), 8 (two-thirds), 12 (full).
     */
    public function span(int $columns): static
    {
        $this->span = max(1, min(12, $columns));

        return $this;
    }

    /** {@inheritdoc} */
    public function options(Request $request): array
    {
        return [];
    }

    /** {@inheritdoc} */
    public function default(): mixed
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Authorization — Martis extension
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /** {@inheritdoc} */
    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) call_user_func($this->canSeeCallback, $request);
    }

    /**
     * Mark this filter as opt-out from lens inheritance.
     *
     * Resource-declared filters are inherited by lenses that do not
     * override `filters()`. Calling this method on a filter excludes
     * it from that inheritance chain while keeping it available on
     * the default index.
     *
     * Martis extension: lens-level filter toggle.
     */
    public function excludeFromLens(bool $value = true): static
    {
        $this->excludeFromLens = $value;

        return $this;
    }

    /** Whether this filter opted out of lens inheritance. */
    public function isExcludedFromLens(): bool
    {
        return $this->excludeFromLens;
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    /**
     * Merge additional metadata.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    // -------------------------------------------------------------------------
    // Schema serialization
    // -------------------------------------------------------------------------

    /** @var list<array{label: string, value: mixed, group?: string}> */
    protected array $resolvedOptions = [];

    /**
     * Resolve options using the given request and store them for serialization.
     *
     * Called by the schema endpoint before toArray() so that options
     * are available without passing Request through toArray().
     *
     * Supports both flat and grouped option formats.
     */
    public function resolveForSchema(Request $request): static
    {
        $raw = $this->options($request);
        $this->resolvedOptions = [];

        foreach ($raw as $label => $value) {
            if (is_array($value)) {
                // Grouped options: 'Group Name' => ['Label' => 'value', ...]
                $groupName = (string) $label;
                foreach ($value as $optionLabel => $optionValue) {
                    $this->resolvedOptions[] = [
                        'label' => (string) $optionLabel,
                        'value' => $optionValue,
                        'group' => $groupName,
                    ];
                }
            } else {
                $this->resolvedOptions[] = [
                    'label' => (string) $label,
                    'value' => $value,
                ];
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'filter',
            'filterType' => $this->filterType()->value,
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'options' => $this->resolvedOptions,
            'default' => $this->default(),
            'span' => $this->span,
            'badge' => $this->badge(),
            'lock' => $this->lockPayloadNow(),
            'meta' => $this->meta(),
        ];
    }
}
