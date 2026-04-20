<?php

namespace Martis\Fields\Concerns;

use Closure;

/**
 * Shared trait for relationship fields that expose dropdown-level /
 * peek / subtitle affordances. BelongsTo, BelongsToMany,
 * MorphTo and MorphToMany carry their own copies of this state today;
 * the HasOne / HasMany / MorphOne / HasOne*Through / HasManyThrough
 * family relies on this trait so every relationship field exposes the
 * same four knobs:
 *
 *   - withSubtitles(bool)         — show a secondary line under the option
 *   - subtitleAttribute(string)   — which model attribute to read
 *   - peekable() / noPeeking()    — hover-preview toggle (default: on)
 *   - relatableQueryUsing(Closure) — scope the list of relatable records
 *
 * Consumers merge {@see self::relatableOptionsMeta()} into their
 * `extraAttributes()` to ship the config to the frontend.
 */
trait ResolvesRelatableOptions
{
    protected bool $withSubtitles = false;

    protected string $subtitleAttribute = 'subtitle';

    protected bool $peekable = true;

    protected ?Closure $relatableQueryClosure = null;

    /**
     * Show a secondary line under each option when the field renders a
     * related-record selector or preview. The subtitle is read from the
     * related model's `$subtitleAttribute` (default `subtitle`).
     */
    public function withSubtitles(bool $value = true): static
    {
        $this->withSubtitles = $value;

        return $this;
    }

    /**
     * Customise the attribute read as the subtitle. Implicitly enables
     * {@see self::withSubtitles()} so a single call is enough.
     */
    public function subtitleAttribute(string $attribute): static
    {
        $this->subtitleAttribute = $attribute;
        $this->withSubtitles = true;

        return $this;
    }

    /**
     * Toggle the peek/preview affordance on the related record.
     * Defaults to true — call `noPeeking()` or pass `false` to disable.
     */
    public function peekable(bool $value = true): static
    {
        $this->peekable = $value;

        return $this;
    }

    /** Disable peek/preview. Sugar for `peekable(false)`. */
    public function noPeeking(): static
    {
        $this->peekable = false;

        return $this;
    }

    /**
     * Scope the relatable-records query. The Closure receives
     * `(\Illuminate\Http\Request $request, \Illuminate\Database\Eloquent\Builder $query)`
     * and may mutate the builder in place or return a new one.
     */
    public function relatableQueryUsing(Closure $callback): static
    {
        $this->relatableQueryClosure = $callback;

        return $this;
    }

    public function getWithSubtitles(): bool
    {
        return $this->withSubtitles;
    }

    public function getSubtitleAttribute(): string
    {
        return $this->subtitleAttribute;
    }

    public function isPeekable(): bool
    {
        return $this->peekable;
    }

    public function getRelatableQueryClosure(): ?Closure
    {
        return $this->relatableQueryClosure;
    }

    /**
     * Meta bag to merge into `extraAttributes()` so the frontend knows
     * whether to render the subtitle line, the peek icon, etc.
     *
     * @return array<string, mixed>
     */
    protected function relatableOptionsMeta(): array
    {
        $meta = [
            'peekable' => $this->peekable,
        ];

        if ($this->withSubtitles) {
            $meta['withSubtitles'] = true;
            $meta['subtitleAttribute'] = $this->subtitleAttribute;
        }

        return $meta;
    }
}
