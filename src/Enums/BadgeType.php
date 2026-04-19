<?php

namespace Martis\Enums;

/**
 * Built-in badge types used by {@see \Martis\Fields\Badge}'s default
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
