<?php

namespace Martis\Enums;

/**
 * Soft-delete filter for index / relationship panels.
 *
 *   - Active: exclude trashed rows (the Eloquent default)
 *   - With:   include trashed rows alongside active ones
 *   - Only:   show only trashed rows
 */
enum TrashedFilter: string
{
    case Active = '';
    case With = 'with';
    case Only = 'only';
}
