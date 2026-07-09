<?php

declare(strict_types=1);

namespace Martis\Concerns;

use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Contracts\LayoutContract;

/**
 * Default `fields()` implementation for `Martis\Contracts\ProvidesFields`.
 *
 * A Tool opts into field support by declaring
 * `implements ProvidesFields` and `use ProvidesToolFields`, then
 * overriding `fields()` to return its own Field builders — the same
 * shape as a Resource's `fields()`, layout wrappers included. Without an
 * override this trait returns an empty array, so adding the interface
 * alone does not force a Tool to define any fields.
 */
trait ProvidesToolFields
{
    /**
     * Define the fields for this entity. Empty by default — override to
     * return a `list<FieldContract|LayoutContract>` (e.g.
     * `[Text::make('Title')]`, optionally wrapped in `Section`/`Panel`/
     * `TabGroup` layouts).
     *
     * @return list<FieldContract|LayoutContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }
}
