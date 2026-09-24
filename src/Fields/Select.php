<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Fields\Concerns\HasChoiceOptions;

/**
 * Dropdown select field.
 *
 * Renders as a PrimeReact Dropdown in the React frontend. `options()` reads
 * `[value => label]`, the order Nova uses, and also takes a list, grouped
 * options, an enum class or a closure (see HasChoiceOptions). The control
 * can also search its options (`searchableOptions()`), accept values
 * outside the list (`allowCustomValues()`) and ask the server for matches
 * as the user types (`searchOptionsUsing()`).
 */
class Select extends Field
{
    use HasChoiceOptions;

    /**
     * Whether index/detail views render the option label or the raw
     * stored value. Default `true` matches the long-standing frontend
     * behaviour where SelectFieldDisplay always resolved value → label.
     * The flag exists for API parity with {@see MultiSelect} and so a
     * consumer can opt out via {@see self::displayUsingValues()}.
     */
    protected bool $displayLabels = true;

    /**
     * Whether the form control renders a search box above the option list
     * (PrimeReact `filter`). Distinct from {@see Field::searchable()}, which
     * makes the COLUMN part of the resource search: this flag only changes
     * how the dropdown narrows its own options. v1.37.0.
     */
    protected bool $searchableOptions = false;

    /**
     * Whether the form control accepts a typed value that is not one of the
     * options (PrimeReact `editable`). The stored value may then fall
     * outside `getOptions()`; index and detail render the raw value when no
     * option matches. Validating against the option list stays the
     * consumer's call (`Rule::in`), the field never adds it. v1.37.0.
     */
    protected bool $allowCustomValues = false;

    /**
     * Server-side option search. When set, the frontend stops filtering the
     * option list locally and asks the owning Resource / Tool endpoint for
     * `searchOptions($term)` as the user types (see FieldOptionsController).
     * The initial list still comes from `getOptions()`. v1.37.0.
     *
     * @var (\Closure(string, Request|null): mixed)|null
     */
    protected ?\Closure $searchOptionsResolver = null;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'select';
    }

    /**
     * {@inheritdoc}
     *
     * The value is checked against static options, see
     * HasChoiceOptions::warnIfStoredAsLabel().
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $value = parent::resolve($model, $attribute);

        $this->warnIfStoredAsLabel($model, $value);

        return $value;
    }

    /**
     * Render the option label (not the raw value) on index and detail.
     *
     * Calling this is currently a no-op because Select renders labels
     * by default — but it documents intent and keeps the API symmetric
     * with {@see MultiSelect::displayUsingLabels()} so generic code that
     * calls the method on either field type works.
     */
    public function displayUsingLabels(): static
    {
        $this->displayLabels = true;

        return $this;
    }

    /**
     * Render the raw stored value (not the option label) on index and
     * detail. Useful when the value is itself a meaningful identifier
     * (e.g. an ISO code) and the label is just a humanised alias.
     */
    public function displayUsingValues(): static
    {
        $this->displayLabels = false;

        return $this;
    }

    /**
     * Whether the field is currently configured to render labels
     * instead of raw values on index and detail.
     */
    public function isDisplayingLabels(): bool
    {
        return $this->displayLabels;
    }

    /**
     * Render a search box above the options so the user can narrow a long
     * list by label or value. Nova's `Select::searchable()` maps to this
     * method; in Martis `searchable()` is the column-search flag.
     */
    public function searchableOptions(bool $value = true): static
    {
        $this->searchableOptions = $value;

        return $this;
    }

    /**
     * Whether the option list renders with a search box.
     */
    public function hasSearchableOptions(): bool
    {
        return $this->searchableOptions;
    }

    /**
     * Accept a typed value that is not one of the options. Pairs well with
     * {@see self::searchableOptions()} for "pick from the list or type a
     * new one" controls (an LLM model id, a tag, an SKU).
     */
    public function allowCustomValues(bool $value = true): static
    {
        $this->allowCustomValues = $value;

        return $this;
    }

    /**
     * Whether the form control accepts values outside the option list.
     */
    public function allowsCustomValues(): bool
    {
        return $this->allowCustomValues;
    }

    /**
     * Resolve options on the server from the user's search term instead of
     * shipping the whole list to the browser. The closure receives the raw
     * term (may be empty: the panel just opened) and the current request,
     * and returns the same shapes `options()` accepts, in the same order
     * (`[value => label]`). Implies
     * {@see self::searchableOptions()}. Only Resource forms and Tools that
     * implement ProvidesFields expose the endpoint; anywhere else the field
     * falls back to local filtering over `getOptions()`.
     *
     *   Select::make('model')
     *       ->options(fn () => ModelCatalog::top(50))
     *       ->searchOptionsUsing(fn (string $term) => ModelCatalog::search($term, limit: 50));
     *
     * @param  \Closure(string, Request|null): mixed  $resolver
     */
    public function searchOptionsUsing(\Closure $resolver): static
    {
        $this->searchOptionsResolver = $resolver;
        $this->searchableOptions = true;

        return $this;
    }

    /**
     * Whether a server-side option resolver is registered.
     */
    public function hasRemoteOptionsSearch(): bool
    {
        return $this->searchOptionsResolver !== null;
    }

    /**
     * Run the server-side resolver for a search term and normalise the
     * result like `options()`.
     *
     * @return list<array{label: string, value: int|string, group?: string}>
     */
    public function searchOptions(string $term, ?Request $request = null): array
    {
        if ($this->searchOptionsResolver === null) {
            return [];
        }

        $resolved = ($this->searchOptionsResolver)($term, $request ?? $this->safeRequest());

        if (! is_array($resolved)) {
            return [];
        }

        /** @var array<int|string, mixed> $resolved */
        return $this->normalizeOptions($resolved);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'options' => $this->getOptions(),
            'displayLabels' => $this->displayLabels,
            'searchableOptions' => $this->searchableOptions,
            'allowCustomValues' => $this->allowCustomValues,
            'remoteOptionsSearch' => $this->searchOptionsResolver !== null,
        ];
    }
}
