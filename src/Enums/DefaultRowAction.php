<?php

namespace Martis\Enums;

/**
 * Identifies one of the default per-row actions rendered in the table's
 * Actions column. Used by `Resource::defaultRowActions()` when the
 * resource returns a whitelist.
 */
enum DefaultRowAction: string
{
    case View = 'view';
    case Edit = 'edit';
    case Delete = 'delete';
}
