<?php

namespace Martis\Fields;

/**
 * Hidden field.
 *
 * Renders as <input type="hidden"> on forms. Invisible in the UI,
 * never shown on index or detail views.
 *
 * A Hidden value is client-controlled and a hidden input is not a trust
 * boundary: `fill()` writes whatever the request posts for the attribute, so
 * anyone who may create or update the record can edit the request and send
 * another value. Use it for values the user may legitimately choose (a UI
 * hint, a return URL, a default the user could change anyway), never for a
 * tenant, an owner or any other column that scopes access. Set those
 * server-side instead: a model event (`creating`), `Resource::beforeSave()`,
 * a `fillUsing()` callback that ignores the posted value, or a `readonly()`
 * field (`fill()` never writes it) whose value the application writes.
 */
class Hidden extends Field
{
    /** Create a hidden field, invisible in index and detail views. */
    public function __construct(string $attribute, ?string $label = null)
    {
        parent::__construct($attribute, $label ?? $attribute);
        $this->showOnIndex = false;
        $this->showOnDetail = false;
    }

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'hidden';
    }
}
