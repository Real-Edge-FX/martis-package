<?php

namespace Martis\Enums;

/**
 * Where to navigate after saving a related record in a HasMany context.
 */
enum HasManyRedirectMode: string
{
    /** Redirect back to the parent resource detail page (default). */
    case Parent = 'parent';

    /** Redirect to the related record's own detail page. */
    case Detail = 'detail';
}
