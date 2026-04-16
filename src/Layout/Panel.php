<?php

namespace Martis\Layout;

use Martis\Contracts\FieldContract;
use Martis\Contracts\LayoutContract;
use Martis\FieldContext;
use Martis\Fields\Field;

/**
 * Panel — a visual grouping of fields with an optional title.
 *
 * Nova v5 parity: Panel::make('Title', [fields...])
 *   ->collapsible()
 *   ->collapsedByDefault()
 *   ->limit(int)
 *
 * Panels can appear in fieldsForCreate(), fieldsForUpdate(), and fieldsForDetail().
 * They do NOT appear on index views (fields are flattened for index).
 *
 * @phpstan-consistent-constructor
 */
class Panel implements LayoutContract
{
    protected string $title;

    /** Subtitle/description shown below the panel title. Martis extension. */
    protected ?string $description = null;

    /** @var list<FieldContract> */
    protected array $fields;

    protected bool $collapsible = false;

    protected bool $collapsedByDefault = false;

    protected ?int $limit = null;

    /**
     * @param  list<FieldContract>  $fields
     */
    public function __construct(string $title, array $fields)
    {
        $this->title = $title;
        $this->fields = $fields;
    }

    /**
     * Create a new Panel instance.
     *
     * @param  list<FieldContract>  $fields
     */
    public static function make(string $title, array $fields): static
    {
        return new static($title, $fields);
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set a description/subtitle shown below the panel title.
     *
     * Martis extension: Nova v5 does not support descriptions on Panels.
     */
    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** Allow the panel to be collapsed/expanded by the user. */
    public function collapsible(): static
    {
        $this->collapsible = true;

        return $this;
    }

    /** Start the panel in a collapsed state (implies collapsible). */
    public function collapsedByDefault(): static
    {
        $this->collapsible = true;
        $this->collapsedByDefault = true;

        return $this;
    }

    /**
     * Limit the number of visible fields before a "Show more" toggle appears.
     *
     * When set, only the first $count fields are shown by default.
     * A "Show more / Show less" control reveals the rest.
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
        $all = Field::filterForContext($this->fields, $context);

        if (empty($all)) {
            return null;
        }

        // Panel only stores FieldContract items; cast the filtered result back.
        /** @var list<FieldContract> $filtered */
        $filtered = $all;

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
            'type' => 'panel',
            'title' => $this->title,
            'description' => $this->description,
            'collapsible' => $this->collapsible,
            'collapsedByDefault' => $this->collapsedByDefault,
            'limit' => $this->limit,
            'fields' => array_map(fn (FieldContract $f): array => $f->toArray(), $this->fields),
        ];
    }
}
