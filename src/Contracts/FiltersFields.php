<?php

namespace Martis\Contracts;

/**
 * A layout container that can rebuild itself with part of its fields.
 *
 * Panel, Section, TabGroup and Tab implement it, so a field list can drop
 * the fields a user cannot see (or may not see on a record) and keep its
 * layout: see `Field::filterLayoutFields()`. A custom layout container
 * that does not implement it gives way to the fields it keeps.
 */
interface FiltersFields
{
    /**
     * Return a copy holding only the fields `$keep` accepts, at every depth,
     * without the nested containers left with none. Returns null when it
     * accepts none.
     *
     * @param  \Closure(FieldContract): bool  $keep
     */
    public function filterFields(\Closure $keep): ?static;
}
