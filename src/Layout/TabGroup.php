<?php

namespace Martis\Layout;

use Martis\Contracts\FieldContract;
use Martis\Contracts\FiltersFields;
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
class TabGroup implements FiltersFields, LayoutContract
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

    /** {@inheritdoc} */
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

    /**
     * Return a new TabGroup holding only the fields `$keep` accepts: each
     * tab keeps those it holds, and a tab left with none is left out. Null
     * when no tab is left (see `Field::filterLayoutFields()`).
     *
     * @param  \Closure(FieldContract): bool  $keep
     */
    public function filterFields(\Closure $keep): ?static
    {
        $tabs = [];

        foreach ($this->tabs as $tab) {
            $filtered = $tab->filterFields($keep);
            if ($filtered !== null) {
                $tabs[] = $filtered;
            }
        }

        if ($tabs === []) {
            return null;
        }

        $clone = clone $this;
        $clone->tabs = $tabs;

        return $clone;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return [
            'type' => 'tab_group',
            'tabs' => array_map(fn (Tab $t): array => $t->toArray(), $this->tabs),
        ];
    }
}
