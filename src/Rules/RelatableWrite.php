<?php

namespace Martis\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
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
     */
    public function __construct(
        public readonly Request $request,
        public readonly ?string $sourceResourceClass,
        public readonly ?Model $record = null,
    ) {}

    /**
     * The `Relatable` rule of `$field`, or `null` when it is not a
     * relationship field the rule checks.
     */
    public function ruleFor(FieldContract $field): ?Relatable
    {
        return Relatable::forField($field, $this->request, $this->sourceResourceClass, $this->record);
    }
}
