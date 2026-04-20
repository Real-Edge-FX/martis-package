<?php

namespace Martis\Layout;

use Martis\Contracts\FieldContract;
use Martis\Contracts\LayoutContract;
use Martis\FieldContext;
use Martis\Fields\Field;

/**
 * Tab — a single named tab containing fields and/or panels.
 *
 * Usage: Tab::make('Title', [fields/panels...])
 *
 * Tabs must be grouped inside a TabGroup. They cannot appear at the top
 * level of a resource fields() array directly — use TabGroup::make().
 *
 * @phpstan-consistent-constructor
 */
class Tab
{
    protected string $title;

    /** @var list<FieldContract|Panel> */
    protected array $content;

    /**
     * @param  list<FieldContract|Panel>  $content
     */
    public function __construct(string $title, array $content)
    {
        $this->title = $title;
        $this->content = $content;
    }

    /**
     * Create a new Tab instance.
     *
     * @param  list<FieldContract|Panel>  $content
     */
    public static function make(string $title, array $content): static
    {
        return new static($title, $content);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Return a new Tab containing only content visible in the given context.
     * Returns null when no content is visible.
     */
    public function filterForContext(FieldContext $context): ?static
    {
        $filtered = [];

        foreach ($this->content as $item) {
            if ($item instanceof LayoutContract) {
                $result = $item->filterForContext($context);
                if ($result !== null) {
                    $filtered[] = $result;
                }
            } elseif ($item instanceof FieldContract) {
                // Apply same visibility rules as Field::filterForContext
                if ($item->isVisibleForContext($context)) {
                    $filtered[] = $item;
                }
            }
        }

        if (empty($filtered)) {
            return null;
        }

        $clone = clone $this;
        $clone->content = $filtered;

        return $clone;
    }

    /**
     * Return all nested FieldContract instances (flattened).
     *
     * @return list<FieldContract>
     */
    public function flattenFields(): array
    {
        $fields = [];

        foreach ($this->content as $item) {
            if ($item instanceof LayoutContract) {
                foreach ($item->flattenFields() as $f) {
                    $fields[] = $f;
                }
            } elseif ($item instanceof FieldContract) {
                $fields[] = $item;
            }
        }

        return $fields;
    }

    /**
     * Serialize the tab for the JSON API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serializedContent = [];

        foreach ($this->content as $item) {
            if ($item instanceof LayoutContract) {
                $serializedContent[] = $item->toArray();
            } elseif ($item instanceof FieldContract) {
                $serializedContent[] = $item->toArray();
            }
        }

        return [
            'type' => 'tab',
            'title' => $this->title,
            'fields' => $serializedContent,
        ];
    }
}
