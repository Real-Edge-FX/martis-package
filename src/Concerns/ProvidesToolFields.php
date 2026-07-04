<?php

declare(strict_types=1);

namespace Martis\Concerns;

use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;

/**
 * Default `fields()` implementation for `Martis\Contracts\ProvidesFields`.
 *
 * A Tool opts into field support by declaring
 * `implements ProvidesFields` and `use ProvidesToolFields`, then
 * overriding `fields()` to return its own Field builders — the same
 * shape as `Action::fields(Request $request): array`. Without an
 * override this trait returns an empty array, so adding the interface
 * alone does not force a Tool to define any fields.
 */
trait ProvidesToolFields
{
    /**
     * Define the fields for this entity. Empty by default — override
     * to return a `list<FieldContract>` (e.g. `[Text::make('Title')]`).
     *
     * @return list<FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }
}
