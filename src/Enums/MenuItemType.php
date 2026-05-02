<?php

namespace Martis\Enums;

/**
 * Kind of sidebar / main-menu entry emitted in the navigation payload.
 */
enum MenuItemType: string
{
    case Link = 'link';
    case Resource = 'resource';
    case Tool = 'tool';
    case Dashboard = 'dashboard';
    case Lens = 'lens';
    case Filter = 'filter';
}
