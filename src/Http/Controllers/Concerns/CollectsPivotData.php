<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Martis\Fields\Field;
use Martis\Http\Resources\JsonErrorResponse;

/**
 * Validate the pivot fields of an attach or a pivot update and collect the
 * values the request may write to the pivot row.
 *
 * The BelongsToMany and MorphToMany controllers write the pivot row through
 * Eloquent's `attach()` and `updateExistingPivot()`, never through
 * `Field::fill()`, so the write rules that `fill()` and the resource
 * controllers apply to a record field are applied here:
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
 * immutable field on the resource endpoint.
 */
trait CollectsPivotData
{
    use BuildsFieldRules;

    /**
     * Validate the pivot fields and return the values to write, or the 422
     * response of a failed validation.
     *
     * @param  list<mixed>  $pivotFields
     * @return array<string, mixed>|IlluminateJsonResponse
     */
    protected function collectPivotData(Request $request, array $pivotFields, bool $isUpdate): array|IlluminateJsonResponse
    {
        $fields = array_values(array_filter($pivotFields, static fn (mixed $field): bool => $field instanceof Field));

        $rules = [];
        $attributes = [];
        foreach ($fields as $field) {
            $rules[$field->attribute()] = $this->buildFieldRules($field, $isUpdate);
            $attributes[$field->attribute()] = $field->label();
        }

        if ($rules !== []) {
            $validator = Validator::make($request->all(), $rules, [], $attributes);
            if ($validator->fails()) {
                return JsonErrorResponse::validation(
                    $validator->errors()->toArray(),
                    'Validation failed.',
                )->toResponse();
            }
        }

        $data = [];
        foreach ($fields as $field) {
            $attribute = $field->attribute();

            if ($isUpdate) {
                if (! $field->isReadonly() && ! $field->isImmutable() && $request->has($attribute)) {
                    $data[$attribute] = $request->input($attribute);
                }

                continue;
            }

            if (! $field->isReadonly() && $request->has($attribute)) {
                $data[$attribute] = $request->input($attribute);

                continue;
            }

            $default = $field->getDefaultValue();
            if ($default !== null) {
                $data[$attribute] = $default;
            }
        }

        return $data;
    }
}
