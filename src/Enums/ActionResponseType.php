<?php

namespace Martis\Enums;

enum ActionResponseType: string
{
    case Message = 'message';
    case Danger = 'danger';
    case Redirect = 'redirect';
    case Visit = 'visit';
    case OpenInNewTab = 'openInNewTab';
    case Download = 'download';
    case Emit = 'emit';
    case Modal = 'modal';
}
