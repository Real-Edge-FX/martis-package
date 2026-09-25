<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Martis\Contracts\FieldContract;
use Martis\Fields\Field;
use Martis\Fields\Repeater;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Resource;
use Martis\Rules\RelatableWrite;

/**
 * Validate the pivot fields of an attach or a pivot update and collect the
 * values to write to the pivot row.
 *
 * The BelongsToMany and MorphToMany controllers write the pivot row through
 * Eloquent's `attach()` and `updateExistingPivot()`. The values come from
 * each pivot field's own `fill()`, run on a pivot model of the relationship's
 * class, so a pivot field writes what it writes on a record: a `fillUsing()`
 * callback runs, a computed field writes nothing, a structured field is
 * encoded (or handed to the pivot class's cast), a `Boolean` stores a
 * boolean. On top of `fill()`, the write rules the resource controllers
 * apply to a record field hold here:
 *
 * - a `readonly()` pivot field never takes its value from the request: the
 *   attach stores its `default()` when it has one, as it does for any pivot
 *   field the request omits, and the pivot update leaves the column alone;
 * - a pivot field the user cannot see (`canSee()`, or `canSeeForModel()` for
 *   the pivot row: the stored row on a pivot update, the new row, before any
 *   value is written to it, on attach) is written the same way as a readonly
 *   one, is not validated, and `presentPivotValues()` leaves it out of the
 *   pivot values sent back, as the resource endpoints leave out a field of
 *   the record the user cannot see;
 * - an `immutable()` pivot field is written on attach and skipped on the
 *   pivot update, as the resource's own update and the inline relationship
 *   updates skip an immutable field.
 *
 * Every other pivot field is validated through `BuildsFieldRules`, so a
 * value the request sends for a readonly or immutable field runs its rules,
 * like an immutable field on the resource endpoint. The rules come from the
 * same `buildWriteValidation()` the record endpoints use, so a pivot
 * `Repeater` validates the fields inside its rows and a field's custom
 * messages apply.
 *
 * A pivot `Repeater` writes its rows as on a record: a row keeps the stored
 * value of a field it cannot write (readonly, computed, hidden from the user,
 * immutable on a stored row), so the pivot update reads the stored rows of
 * the pivot row first, and `presentPivotValues()` gives its rows without the
 * fields the user cannot see wherever the pivot values are sent back.
 */
trait CollectsPivotData
{
    use BuildsFieldRules;

    /**
     * Validate the pivot fields and return the values to write, or the 422
     * response of a failed validation. `$relatedId` names the attached
     * record a pivot update writes. `$sourceResourceClass` is the parent
     * resource, the source of the relatable hooks of the pivot fields'
     * pickers: a `BelongsTo`, `MorphTo` or `Tag` among them is checked
     * against its picker's query (see `Martis\Rules\Relatable`).
     *
     * @param  list<mixed>  $pivotFields
     * @param  EloquentBelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @param  class-string<\Martis\Resource>|null  $sourceResourceClass
     * @return array<string, mixed>|IlluminateJsonResponse
     */
    protected function collectPivotData(Request $request, array $pivotFields, bool $isUpdate, EloquentBelongsToMany $relation, int|string|null $relatedId = null, ?string $sourceResourceClass = null): array|IlluminateJsonResponse
    {
        $fields = array_values(array_filter($pivotFields, static fn (mixed $field): bool => $field instanceof Field));

        // A blank pivot of the relationship's own class (pivot values and
        // the morph type already set), so each field sees the casts it will
        // be stored through. On a pivot update it holds the stored rows of
        // the pivot Repeaters, which the rows sent continue.
        $pivot = $relation->newPivot();
        // The pivot row the fields' canSeeForModel() decides on: the stored
        // row on a pivot update, the new row (still blank) on attach.
        $row = $pivot;
        if ($isUpdate && $relatedId !== null) {
            $row = $this->storedPivotRow($relation, $relatedId) ?? $pivot;
            $this->withStoredRepeaterRows($pivot, $fields, $row);
        }
        $preset = $pivot->getAttributes();

        $visible = array_values(array_filter(
            Field::filterForModel($fields, $request, $row),
            static fn (Field $field): bool => $field->isAuthorizedToSee($request),
        ));
        $relatable = $sourceResourceClass !== null ? new RelatableWrite($request, $sourceResourceClass, $row) : null;
        $validation = $this->buildWriteValidation($visible, $request->all(), $isUpdate, [], $pivot, $relatable);

        if ($validation['rules'] !== []) {
            $validator = Validator::make($request->all(), $validation['rules'], $validation['messages'], $validation['attributes']);
            if ($validator->fails()) {
                return JsonErrorResponse::validation(
                    $validator->errors()->toArray(),
                    'Validation failed.',
                )->toResponse();
            }
        }

        foreach ($fields as $field) {
            $attribute = $field->attribute();
            // Neither a readonly field nor one the user cannot see takes its
            // value from the request.
            $writable = ! $field->isReadonly() && in_array($field, $visible, true);

            if ($isUpdate) {
                if ($writable && ! $field->isImmutable() && $request->has($attribute)) {
                    $field->fill($pivot, $request->input($attribute));
                }

                continue;
            }

            if ($writable && $request->has($attribute)) {
                $field->fill($pivot, $request->input($attribute));

                continue;
            }

            $default = $field->getDefaultValue();
            if ($default !== null) {
                // A readonly or hidden field takes nothing from the request,
                // but its default goes through its own fill like any other
                // value.
                (clone $field)->readonly(false)->fill($pivot, $default);
            }
        }

        return $this->pivotWriteValues($relation, $pivot, $preset);
    }

    /**
     * Put on `$pivot` the stored value of each pivot Repeater among
     * `$fields`, read from `$stored`, the pivot row a pivot update writes,
     * so the rows the update sends continue the stored ones.
     *
     * @param  list<Field>  $fields
     */
    private function withStoredRepeaterRows(Pivot $pivot, array $fields, Pivot $stored): void
    {
        $values = [];
        foreach ($fields as $field) {
            if ($field instanceof Repeater && array_key_exists($field->attribute(), $stored->getAttributes())) {
                $values[$field->attribute()] = $stored->getAttributes()[$field->attribute()];
            }
        }

        if ($values !== []) {
            $pivot->setRawAttributes(array_merge($pivot->getAttributes(), $values), true);
        }
    }

    /**
     * The attributes of the pivot fields a new row hides: those the user can
     * see (`canSee()`) but whose `canSeeForModel()` denies the row the attach
     * would write. The attachable list sends them, so the attach modal leaves
     * those fields out instead of offering an input the attach ignores.
     *
     * @param  list<FieldContract>  $pivotFields
     * @param  EloquentBelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @return list<string>
     */
    protected function pivotFieldsHiddenOnAttach(Request $request, array $pivotFields, EloquentBelongsToMany $relation): array
    {
        $seen = array_values(array_filter(
            $pivotFields,
            static fn ($field): bool => $field instanceof Field && $field->isAuthorizedToSee($request),
        ));

        return Field::hiddenAttributes($seen, Field::filterForModel($seen, $request, $relation->newPivot()));
    }

    /**
     * Pivot values as they are sent back: without the value of a pivot field
     * the user cannot see (`canSee()`, or `canSeeForModel()` for `$row`, the
     * pivot row the values come from), and with the rows of each pivot
     * Repeater as its read gives them (without the row fields the user
     * cannot see); the other values as they are.
     *
     * With `$row`, the attributes of the pivot fields `canSeeForModel()`
     * hides for it are listed under `_hidden` (absent when it hides none),
     * as a record lists its own: the schema lists the relationship's pivot
     * fields, and the panel leaves those out of the row's cells and of its
     * pivot edit form.
     *
     * @param  list<mixed>  $pivotFields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function presentPivotValues(Request $request, array $pivotFields, array $values, ?Model $row = null): array
    {
        $hidden = [];

        foreach ($pivotFields as $field) {
            if (! $field instanceof Field || ! $field->isAuthorizedToSee($request)) {
                if ($field instanceof Field) {
                    unset($values[$field->attribute()]);
                }

                continue;
            }

            if ($row !== null && ! $field->isAuthorizedForModel($request, $row)) {
                $hidden[] = $field->attribute();
                unset($values[$field->attribute()]);
            } elseif ($field instanceof Repeater && array_key_exists($field->attribute(), $values)) {
                $values[$field->attribute()] = $field->resolveRows($values[$field->attribute()]);
            }
        }

        if ($hidden !== []) {
            $values['_hidden'] = array_values(array_unique($hidden));
        }

        return $values;
    }

    /**
     * The attributes the fields set on the pivot, in the form `attach()` and
     * `updateExistingPivot()` expect: with the stock pivot class Eloquent
     * writes them as they are, so each keeps its stored value; with a custom
     * pivot class (`->using()`) Eloquent fills them through the class first,
     * so a cast attribute goes back as its cast value and is encoded once.
     *
     * @param  EloquentBelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    private function pivotWriteValues(EloquentBelongsToMany $relation, Pivot $pivot, array $preset): array
    {
        $customClass = $relation->getPivotClass() !== Pivot::class;

        $values = [];
        foreach ($pivot->getAttributes() as $key => $stored) {
            if (array_key_exists($key, $preset) && $preset[$key] === $stored) {
                continue;
            }

            $values[$key] = $customClass && $pivot->hasCast($key) ? $pivot->getAttribute($key) : $stored;
        }

        return $values;
    }
}
