<?php

namespace Martis\Contracts;

/**
 * Returned by `Resource::confirmUnsavedChanges()` when the resource wants to
 * customise the "unsaved changes" confirmation dialog (icon, colours,
 * labels, copy). Returning an implementation of this contract is equivalent
 * to returning `true` — it means the dialog IS enabled, AND it should use
 * these overrides on top of the package defaults.
 */
interface UnsavedChangesConfigContract
{
    /**
     * Serialise the configuration for the frontend schema payload.
     *
     * Every key is optional — the dialog falls back to localised defaults
     * whenever a key is missing or null.
     *
     * @return array{
     *   title?: ?string,
     *   body?: ?string,
     *   icon?: ?string,
     *   iconColor?: ?string,
     *   confirmLabel?: ?string,
     *   confirmColor?: ?string,
     *   cancelLabel?: ?string,
     * }
     */
    public function toArray(): array;
}
