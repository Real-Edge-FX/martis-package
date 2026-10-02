<?php

namespace Martis\Fields\Concerns;

/**
 * The writes a `HasMany`, `HasOne`, `MorphMany` or `MorphOne` panel allows
 * through its relationship.
 *
 * `canCreate()`, `canUpdate()` and `canDelete()` turn a write off for the
 * field, not only its button: the relationship controllers read the flags
 * through `allowsRelatedWrite()` and answer 403 for a write the field
 * turned off, before any policy runs. The policies still gate every write
 * the field leaves on. The Through variants keep `canCreate` off for good.
 *
 * The using class declares `$canCreateRelated`, `$canUpdateRelated` and
 * `$canDeleteRelated`.
 */
trait ControlsRelatedWrites
{
    /**
     * Whether the field leaves a write through the relationship on:
     * `create`, `update` or `delete` (any other name has no flag).
     */
    public function allowsRelatedWrite(string $action): bool
    {
        return match ($action) {
            'create' => $this->canCreateRelated,
            'update' => $this->canUpdateRelated,
            'delete' => $this->canDeleteRelated,
            default => true,
        };
    }
}
