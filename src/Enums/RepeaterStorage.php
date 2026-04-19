<?php

namespace Martis\Enums;

/**
 * Persistence mode for {@see \Martis\Fields\Repeater}.
 *
 * - Json: serialised array on a JSON cast attribute of the parent model.
 * - HasMany: rows live in a child table and are upserted via `uniqueField`.
 */
enum RepeaterStorage: string
{
    case Json = 'json';
    case HasMany = 'has_many';

    /**
     * ⭐ Martis differential — every row type lives in a single child table
     * discriminated by a `type` column, with field values serialised into
     * a `payload` JSON column. Solves Nova's "1 table per repeatable type"
     * limitation for page-builder-style use cases.
     */
    case Polymorphic = 'polymorphic';
}
