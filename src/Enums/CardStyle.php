<?php

namespace Martis\Enums;

/**
 * Visual style for metric cards.
 *
 * Martis extension — Nova v5 does not support card styling.
 */
enum CardStyle: string
{
    case Default = 'default';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
}
