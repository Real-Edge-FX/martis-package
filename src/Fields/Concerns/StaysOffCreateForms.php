<?php

namespace Martis\Fields\Concerns;

use Martis\FieldContext;

/**
 * Keeps a many-to-many relationship field off every create form.
 *
 * `BelongsToMany` and `MorphToMany` use it. A pivot row needs the key of the
 * record it attaches to, and a create form (the create page, the create
 * drawer, the inline-create modal) has no record yet, so, like Nova, which
 * drops its `ListableField`s from every creation form, no visibility call
 * (`showOnCreating()`, `showOnForms()`, `onlyOnForms()`) brings the field
 * onto one. The update form and the detail page keep it: records are
 * attached once the record exists.
 */
trait StaysOffCreateForms
{
    /** {@inheritdoc} */
    public function isVisibleForContext(FieldContext $context): bool
    {
        if ($context === FieldContext::CREATE || $context === FieldContext::INLINE_CREATE) {
            return false;
        }

        return parent::isVisibleForContext($context);
    }

    /** {@inheritdoc} */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['showOnCreate'] = false;

        return $array;
    }
}
