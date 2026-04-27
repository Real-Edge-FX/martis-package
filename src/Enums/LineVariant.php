<?php

namespace Martis\Enums;

use Martis\Fields\Line;
use Martis\Fields\Stack;

/**
 * Visual variants for the {@see Line} field used inside
 * {@see Stack}. Controls typography (size, weight, color,
 * monospace) without exposing raw CSS.
 */
enum LineVariant: string
{
    case Heading = 'heading';
    case Base = 'base';
    case Small = 'small';
    case Muted = 'muted';
    case Code = 'code';
}
