<?php

namespace Martis\Enums;

/**
 * Visual style for metric cards.
 *
 * Martis extension.
 */
enum CardStyle: string
{
    case Default = 'default';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
}
