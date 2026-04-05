<?php

namespace Martis\Enums;

/**
 * How a HasMany field is displayed on the index page.
 */
enum HasManyIndexDisplay: string
{
    /** Show a count badge (e.g. "12"). */
    case Count = 'count';
}
