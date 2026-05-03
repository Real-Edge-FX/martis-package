<?php

namespace Martis\Enums;

/**
 * Slot identifiers for `Resource::overrides()`. Use the enum cases
 * instead of magic strings so a typo (`'creat'` vs `'create'`) becomes
 * a compile-time error in the host app rather than a silent miss at
 * runtime.
 *
 * The string values are kept stable on purpose — the host
 * `Resource::overrides()` may continue to return string keys for
 * back-compat, and the bundled controllers compare against
 * `DrawerSlot::Create->value` etc. Mixing both in the same return
 * array is allowed:
 *
 *     return [
 *         DrawerSlot::Create->value => DrawerOverride::create()->...,
 *         'update' => DrawerOverride::update()->...,
 *     ];
 *
 * Future versions may tighten the contract to require the enum on
 * the way in.
 */
enum DrawerSlot: string
{
    case Create = 'create';
    case Update = 'update';
    case Detail = 'detail';
    /**
     * Lightweight quick-look surface — typically wired to a row
     * "open quickly" affordance that shows a curated subset of fields
     * without the full detail page chrome. Distinct from `Detail`
     * (the canonical destination after navigating into a record) and
     * from the BelongsTo / MorphTo hover **peek** popover (which
     * surfaces related-record metadata, not a drawer for the current
     * row).
     */
    case Quick = 'quick';
}
