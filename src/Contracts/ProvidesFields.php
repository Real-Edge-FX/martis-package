<?php

declare(strict_types=1);

namespace Martis\Contracts;

use Illuminate\Http\Request;

/**
 * Opt-in contract for any Martis entity that wants to expose Field
 * builders the same way an Action does.
 *
 * Tools do not declare fields by default — a Tool is a free-form page,
 * not a form. A consumer Tool that needs a field-driven surface (a
 * settings form, an import wizard, etc.) implements this contract and
 * uses the `ProvidesToolFields` trait (or supplies its own `fields()`)
 * to opt in. Nothing on the base `Tool` class depends on this contract,
 * so existing Tools are completely unaffected.
 *
 * The returned Field builders are serialized via `Field::toArray()` at
 * the consuming endpoint, exactly like `ActionContract::fields()`.
 */
interface ProvidesFields
{
    /**
     * Define the fields exposed by this entity.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return list<FieldContract>
     */
    public function fields(Request $request): array;
}
