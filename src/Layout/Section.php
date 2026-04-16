<?php

namespace Martis\Layout;

use Martis\Contracts\FieldContract;
use Martis\Contracts\LayoutContract;
use Martis\FieldContext;
use Martis\Fields\Field;

/**
 * Section -- a form-aware layout container with a configurable CSS grid.
 *
 * Unlike Panel (which always uses a 12-column grid), Section exposes
 * Section::columns() so the developer controls the grid width, and
 * individual fields control their width with Field::span().
 *
 * Usage:
 *
 *   Section::make('Timeline', [
 *       Date::make('start_date')->span(6),
 *       Date::make('end_date')->span(6),
 *   ])->columns(12)
 *
 * Scope: create / update forms only.
 * Sections do NOT appear on index or detail views -- use Panel there.
 *
 * @phpstan-consistent-constructor
 */
class Section implements LayoutContract
{
    /** Number of CSS grid columns (default: 12). */
    protected int $columns = 12;

    /** Subtitle/description shown below the section title. Martis extension. */
    protected ?string $description = null;

    protected bool $collapsible = false;

    protected bool $collapsedByDefault = false;

    protected ?int $limit = null;

    /**
     * @param  list<FieldContract>  $fields
     */
    public function __construct(
        protected readonly ?string $title,
        protected array $fields,
    ) {}

    /**
     * Create a new Section instance.
     *
     * @param  list<FieldContract>  $fields
     */
    /**
     * Create a new Section instance.
     *
     * The title is optional — pass an empty string or null to render the section
     * without a header bar. Useful when you want grid layout without a visual label.
     *
     * @param  list<FieldContract>  $fields
     */
    public static function make(?string $title, array $fields): static
    {
        return new static($title, $fields);
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Define the number of columns in the CSS grid for this section.
     *
     * Fields inside the section use Field::span() to declare how many
     * of these columns they occupy. Unspanned fields default to full width.
     *
     * Common values: 12 (quarter/third/half splits), 2 (two equal columns),
     * 3 (thirds), 4 (quarters).
     */
    public function columns(int $columns): static
    {
        $this->columns = max(1, $columns);

        return $this;
    }

    /**
     * Set a description/subtitle shown below the section title.
     *
     * Martis extension: Nova v5 does not support descriptions on layout containers.
     */
    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** Allow the section to be collapsed/expanded by the user. */
    public function collapsible(): static
    {
        $this->collapsible = true;

        return $this;
    }

    /** Start the section in a collapsed state (implies collapsible). */
    public function collapsedByDefault(): static
    {
        $this->collapsible = true;
        $this->collapsedByDefault = true;

        return $this;
    }

    /**
     * Limit the number of visible fields before a Show more toggle appears.
     */
    public function limit(int $count): static
    {
        $this->limit = $count;

        return $this;
    }

    // -------------------------------------------------------------------------
    // LayoutContract
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function filterForContext(FieldContext $context): ?static
    {
        $filtered = Field::filterForContext($this->fields, $context);

        if (empty($filtered)) {
            return null;
        }

        /** @var list<FieldContract> $filtered */
        $clone = clone $this;
        $clone->fields = $filtered;

        return $clone;
    }

    /** {@inheritDoc} */
    public function flattenFields(): array
    {
        return $this->fields;
    }

    /** {@inheritDoc} */
    public function toArray(): array
    {
        return [
            'type' => 'section',
            'title' => $this->title,
            'description' => $this->description,
            'columns' => $this->columns,
            'collapsible' => $this->collapsible,
            'collapsedByDefault' => $this->collapsedByDefault,
            'limit' => $this->limit,
            'fields' => array_map(fn (FieldContract $f): array => $f->toArray(), $this->fields),
        ];
    }
}
