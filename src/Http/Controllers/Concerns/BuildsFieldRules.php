<?php

namespace Martis\Http\Controllers\Concerns;

use Closure;
use Illuminate\Contracts\Validation\Rule;
use Martis\Contracts\FieldContract;

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
}
