<?php

namespace Martis\Enums;

/**
 * Kind of sidebar / main-menu entry emitted in the navigation payload.
 */
enum MenuItemType: string
{
    case Link = 'link';
    case Resource = 'resource';
}
