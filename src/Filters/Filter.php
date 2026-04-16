<?php

namespace Martis\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FilterContract;

/**
 * Base class for all Martis filters.
 *
 * Nova v5 parity: filters modify the resource index query based on
 * user-selected values. Subclasses must implement apply() and filterType().
 *
 * Martis extensions beyond Nova v5:
 * - canSee() authorization callback (consistent with Field and Action APIs)
 * - Default values correctly applied on frontend initial load
 *
 * @phpstan-consistent-constructor
 */
abstract class Filter implements FilterContract
{
    /** @var array<string, mixed> */
    protected array $meta = [];

    protected ?string $component = null;

    /** Authorization callback — Martis extension. */
    protected ?Closure $canSeeCallback = null;

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
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    abstract public function apply(Request $request, Builder $query, mixed $value): Builder;

    /**
     * The filter type identifier sent to the frontend.
     */
    abstract public function filterType(): string;

    public function name(): string
    {
        return $this->name;
    }

    public function uriKey(): string
    {
        return $this->uriKey ?? Str::kebab($this->name);
    }

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
     * Get the filter options.
     *
     * Override in SelectFilter and BooleanFilter to provide choices.
     * Supports flat and grouped formats:
     *
     * Flat:   ['Active' => 'active', 'Inactive' => 'inactive']
     * Grouped: ['Status' => ['Active' => 'active', 'Inactive' => 'inactive']]
     *
     * @return array<string, mixed>
     */
    public function options(Request $request): array
    {
        return [];
    }

    /**
     * Get the default filter value.
     *
     * Override to provide a pre-selected value when the page first loads.
     * The frontend will apply defaults on initial load and include them
     * in the index query automatically.
     */
    public function default(): mixed
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Authorization — Martis extension
    // -------------------------------------------------------------------------

    /**
     * Set a callback that determines if the filter should be visible.
     *
     * Martis extension: Nova v5 does not support per-filter authorization.
     * Martis provides canSee() on Filters for consistency with Fields and Actions.
     */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /**
     * Determine if the filter should be visible for the given request.
     */
    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback === null) {
            return true;
        }

        return (bool) call_user_func($this->canSeeCallback, $request);
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'filter',
            'filterType' => $this->filterType(),
            'name' => $this->name(),
            'uriKey' => $this->uriKey(),
            'component' => $this->component(),
            'options' => $this->resolvedOptions,
            'default' => $this->default(),
            'meta' => $this->meta(),
        ];
    }
}
