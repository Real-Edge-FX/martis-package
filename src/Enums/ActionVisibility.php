<?php

namespace Martis\Enums;

enum ActionVisibility: string
{
    case Index = 'index';
    case Detail = 'detail';
    case Inline = 'inline';
}
