<?php

namespace Martis\Enums;

/**
 * Visual variants for the {@see \Martis\Fields\Line} field used inside
 * {@see \Martis\Fields\Stack}. Controls typography (size, weight, color,
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
