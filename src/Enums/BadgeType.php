<?php

namespace Martis\Enums;

use Martis\Fields\Badge;

/**
 * Built-in badge types used by {@see Badge}'s default
 * style map. User-supplied types (arbitrary CSS hex colors) remain open
 * and are not constrained by this enum.
 */
enum BadgeType: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
