<?php

namespace Martis\Layout;

use Martis\Contracts\LayoutContract;
use Martis\FieldContext;

/**
 * TabGroup — a set of named tabs rendered as a tabbed navigation UI.
 *
 * Usage:
 *   TabGroup::make([
 *     Tab::make('General', [fields...]),
 *     Tab::make('Details', [fields...]),
 *   ])
 *
 * TabGroup is the top-level container for tabs. It implements LayoutContract
 * and can appear directly in a resource's fields() return value.
 *
 * @phpstan-consistent-constructor
 */
class TabGroup implements LayoutContract
{
    /** @var list<Tab> */
    protected array $tabs;

    /**
     * @param  list<Tab>  $tabs
     */
    public function __construct(array $tabs)
    {
        $this->tabs = $tabs;
    }

    /**
     * Create a new TabGroup.
     *
     * @param  list<Tab>  $tabs
     */
    public static function make(array $tabs): static
    {
        return new static($tabs);
    }

    // -------------------------------------------------------------------------
    // LayoutContract
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function filterForContext(FieldContext $context): ?static
    {
        $filtered = [];

        foreach ($this->tabs as $tab) {
            $result = $tab->filterForContext($context);
            if ($result !== null) {
                $filtered[] = $result;
            }
        }

        if (empty($filtered)) {
            return null;
        }

        $clone = clone $this;
        $clone->tabs = $filtered;

        return $clone;
    }

    /** {@inheritDoc} */
    public function flattenFields(): array
    {
        $fields = [];

        foreach ($this->tabs as $tab) {
            foreach ($tab->flattenFields() as $f) {
                $fields[] = $f;
            }
        }

        return $fields;
    }

    /** {@inheritDoc} */
    public function toArray(): array
    {
        return [
            'type' => 'tab_group',
            'tabs' => array_map(fn (Tab $t): array => $t->toArray(), $this->tabs),
        ];
    }
}
