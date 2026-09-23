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

    /**
     * The filter a request's `?trashed=` names, or `Active` for anything
     * else: no value, an unknown one, or a value that is not a string
     * (`?trashed[]=with`).
     */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Active) : self::Active;
    }
}
