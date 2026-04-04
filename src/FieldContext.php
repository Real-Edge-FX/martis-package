<?php

namespace Martis;

/**
 * Enumeration of all supported field display contexts.
 *
 * Used by the visibility filtering layer to determine which fields
 * should be included in each API response. Keeps context identifiers
 * type-safe and centralized.
 */
enum FieldContext: string
{
    case INDEX = 'index';
    case DETAIL = 'detail';
    case CREATE = 'create';
    case UPDATE = 'update';
    case INLINE_CREATE = 'inline-create';
    case PREVIEW = 'preview';
}
