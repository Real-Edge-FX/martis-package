<?php

namespace Martis\Http\Controllers\Concerns;

use Closure;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Martis\Contracts\FieldContract;
use Martis\Fields\Field;
use Martis\Fields\Repeater;

/**
 * Build the validation rules of a field for a create or an update.
 *
 * Every endpoint that writes a record through its fields builds the rules
 * here: the resource's own create and update, the HasMany / HasOne /
 * MorphMany / MorphOne inline forms and the BelongsToMany / MorphToMany
 * pivot fields on attach and on pivot update. So `creationRules()` apply on
 * every create and attach, `updateRules()` on every update, and every kind
 * of rule a field declares reaches the validator on all of them.
 *
 * On update a field is validated only when the request sends it: the
 * literal `required` string is dropped and `sometimes` leads the list, so
 * a partial payload leaves the other fields alone. Every other rule is
 * kept, whatever its type: strings, `Rule::` builder objects
 * (`Rule::unique()`, `Rule::in()`, `Rule::enum()`, `Rule::requiredIf()`),
 * `ValidationRule` instances and closures. The filter does not type the
 * rule: several of those objects implement neither the `Rule` contract nor
 * `Closure`, so a typed callback throws a `TypeError` on them.
 *
 * `buildWriteValidation()` assembles everything those endpoints hand the
 * validator, including the fields inside the rows of a `Repeater`
 * (`buildNestedFieldValidation()`, which an Action's fields use too).
 */
trait BuildsFieldRules
{
    /**
     * @return list<string|Rule|Closure>
     */
    protected function buildFieldRules(FieldContract $field, bool $isUpdate): array
    {
        $rules = $field->buildRules($isUpdate ? 'update' : 'create');

        if (! $isUpdate) {
            return $rules;
        }

        $rules = array_values(array_filter($rules, static fn (mixed $rule): bool => $rule !== 'required'));

        if (! in_array('sometimes', $rules, true)) {
            array_unshift($rules, 'sometimes');
        }

        return $rules;
    }

    /**
     * Everything the validator needs to check a write of `$fields`: the
     * rules, the custom messages and the attribute names.
     *
     * Each field validates with `buildFieldRules()` under its attribute and
     * is named by its label. A structured value that is neither a list nor a
     * map (`$undecodable`, see `DecodesStructuredValues`) also fails `array`,
     * a multiple `File` / `Image` checks each upload with its item rules, the
     * fields' custom messages (a `unique()` message) apply, and a `Repeater`
     * validates the fields inside every row it receives. `$model` is the
     * record an update writes: a Repeater tells the rows it stores from new
     * ones by it.
     *
     * @param  list<FieldContract>  $fields
     * @param  array<array-key, mixed>  $data  The input the validator runs on.
     * @param  list<string>  $undecodable
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>, attributes: array<string, string>}
     */
    protected function buildWriteValidation(array $fields, array $data, bool $isUpdate, array $undecodable = [], ?Model $model = null): array
    {
        $rules = [];
        $messages = [];
        $attributes = [];

        foreach ($fields as $field) {
            $attribute = $field->attribute();

            $fieldRules = $this->buildFieldRules($field, $isUpdate);

            // A structured value that arrived as a string which is not JSON
            // for a list or map fails here instead of reaching fill().
            if (in_array($attribute, $undecodable, true)) {
                $fieldRules[] = 'array';
            }

            $rules[$attribute] = $fieldRules;

            // Per-item rules of a multiple-file field (MIME type, max size).
            if (method_exists($field, 'buildItemRules')) {
                $itemRules = $field->buildItemRules();
                if ($itemRules !== []) {
                    $rules[$attribute.'.*'] = $itemRules;
                }
            }

            if ($field instanceof Field) {
                $attributes[$attribute] = $field->label();
                $messages = array_merge($messages, $field->validationMessages());
            }
        }

        $nested = $this->buildNestedFieldValidation($fields, $data, $isUpdate ? 'update' : 'create', $model);

        return [
            'rules' => $rules + $nested['rules'],
            'messages' => $messages + $nested['messages'],
            'attributes' => $attributes + $nested['attributes'],
        ];
    }

    /**
     * The validation of the values inside the fields' values: the fields of
     * every row a `Repeater` receives, under
     * `{attribute}.{index}.fields.{field}` (see
     * `Repeater::buildRowValidation()`), `$model` being the record an update
     * writes.
     *
     * @param  iterable<mixed>  $fields
     * @param  array<array-key, mixed>  $data  The input the validator runs on.
     * @param  'create'|'update'|null  $context
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>, attributes: array<string, string>}
     */
    protected function buildNestedFieldValidation(iterable $fields, array $data, ?string $context, ?Model $model = null): array
    {
        $validation = ['rules' => [], 'messages' => [], 'attributes' => []];

        foreach ($fields as $field) {
            if (! $field instanceof Repeater) {
                continue;
            }

            $rows = $field->buildRowValidation($data, $context, $model);
            $validation['rules'] += $rows['rules'];
            $validation['messages'] += $rows['messages'];
            $validation['attributes'] += $rows['attributes'];
        }

        return $validation;
    }
}
