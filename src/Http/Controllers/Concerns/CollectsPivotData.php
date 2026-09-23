<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Martis\Fields\Field;
use Martis\Http\Resources\JsonErrorResponse;

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
 * - an `immutable()` pivot field is written on attach and skipped on the
 *   pivot update, as the resource's own update and the inline relationship
 *   updates skip an immutable field.
 *
 * Every pivot field is still validated through `BuildsFieldRules`, so a
 * value the request sends for a skipped field runs its rules, like an
 * immutable field on the resource endpoint. The rules come from the same
 * `buildWriteValidation()` the record endpoints use, so a pivot `Repeater`
 * validates the fields inside its rows and a field's custom messages apply.
 */
trait CollectsPivotData
{
    use BuildsFieldRules;

    /**
     * Validate the pivot fields and return the values to write, or the 422
     * response of a failed validation.
     *
     * @param  list<mixed>  $pivotFields
     * @param  EloquentBelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @return array<string, mixed>|IlluminateJsonResponse
     */
    protected function collectPivotData(Request $request, array $pivotFields, bool $isUpdate, EloquentBelongsToMany $relation): array|IlluminateJsonResponse
    {
        $fields = array_values(array_filter($pivotFields, static fn (mixed $field): bool => $field instanceof Field));

        $validation = $this->buildWriteValidation($fields, $request->all(), $isUpdate);

        if ($validation['rules'] !== []) {
            $validator = Validator::make($request->all(), $validation['rules'], $validation['messages'], $validation['attributes']);
            if ($validator->fails()) {
                return JsonErrorResponse::validation(
                    $validator->errors()->toArray(),
                    'Validation failed.',
                )->toResponse();
            }
        }

        // A blank pivot of the relationship's own class (pivot values and
        // the morph type already set), so each field sees the casts it will
        // be stored through.
        $pivot = $relation->newPivot();
        $preset = $pivot->getAttributes();

        foreach ($fields as $field) {
            $attribute = $field->attribute();

            if ($isUpdate) {
                if (! $field->isReadonly() && ! $field->isImmutable() && $request->has($attribute)) {
                    $field->fill($pivot, $request->input($attribute));
                }

                continue;
            }

            if (! $field->isReadonly() && $request->has($attribute)) {
                $field->fill($pivot, $request->input($attribute));

                continue;
            }

            $default = $field->getDefaultValue();
            if ($default !== null) {
                // A readonly field takes nothing from the request, but its
                // default goes through its own fill like any other value.
                (clone $field)->readonly(false)->fill($pivot, $default);
            }
        }

        return $this->pivotWriteValues($relation, $pivot, $preset);
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
