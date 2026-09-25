<?php

namespace Martis\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphTo;
use Martis\Resource;

/**
 * The context a write validates its relationship fields in (see
 * {@see Relatable}): the request, the resource whose form declares the
 * fields (the source of the `relatable{PluralModelName}()` hooks, the
 * resource the picker endpoint names) and the record the write fills.
 */
final class RelatableWrite
{
    /**
     * @param  class-string<\Martis\Resource>|null  $sourceResourceClass
     * @param  Model|null  $record  The new model on a create, the stored one on an update, the pivot row for pivot fields
     * @param  list<string>  $controllerWrites  The columns the endpoint writes itself after the fill (the parent key of a relationship panel's inline create): a `BelongsTo` / `MorphTo` on one of them writes nothing from the request, so it is not checked
     */
    public function __construct(
        public readonly Request $request,
        public readonly ?string $sourceResourceClass,
        public readonly ?Model $record = null,
        public readonly array $controllerWrites = [],
    ) {}

    /**
     * The `Relatable` rule of `$field`, or `null` when it is not a
     * relationship field the rule checks.
     */
    public function ruleFor(FieldContract $field): ?Relatable
    {
        if ($field instanceof BelongsTo && in_array($field->attribute(), $this->controllerWrites, true)) {
            return null;
        }

        if ($field instanceof MorphTo && in_array($field->getMorphIdColumn(), $this->controllerWrites, true)) {
            return null;
        }

        return Relatable::forField($field, $this->request, $this->sourceResourceClass, $this->record);
    }

    /**
     * The `Relatable` rule of `$field` in a Repeater row (see
     * `Repeater::buildRowValidation()`): the row stores the value, so the
     * checks of a relationship of the record do not apply.
     */
    public function rowRuleFor(FieldContract $field): ?Relatable
    {
        return Relatable::forField($field, $this->request, $this->sourceResourceClass, null, inRow: true);
    }
}
