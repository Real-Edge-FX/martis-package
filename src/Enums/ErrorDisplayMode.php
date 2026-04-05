<?php

namespace Martis\Enums;

enum ErrorDisplayMode: string
{
    case Inline = 'inline';
    case Toast = 'toast';
    case Both = 'both';
}
