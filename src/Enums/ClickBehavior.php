<?php

namespace Martis\Enums;

enum ClickBehavior: string
{
    case Modal = 'modal';
    case NewTab = 'new_tab';
    case SamePage = 'same_page';
}
