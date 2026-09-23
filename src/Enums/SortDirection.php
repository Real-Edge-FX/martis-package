<?php

namespace Martis\Enums;

enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    /**
     * The direction a request's `?direction=` names, in any letter case, or
     * `Asc` for anything else: no value, an unknown one, or a value that is
     * not a string (`?direction[]=desc`).
     */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower($value)) ?? self::Asc) : self::Asc;
    }
}
