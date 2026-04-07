<?php

namespace Martis\Enums;

enum ActionExecutionMode: string
{
    case Normal = 'normal';
    case Inline = 'inline';
    case Standalone = 'standalone';
    case Sole = 'sole';
}
