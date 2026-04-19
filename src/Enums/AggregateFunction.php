<?php

namespace Martis\Enums;

/**
 * Aggregate function used by relationship fields (HasOneOfMany,
 * MorphOneOfMany) to ship a metric tile alongside the promoted record.
 *
 * ⭐ Martis differential — Nova does not expose aggregate metrics on
 * OfMany relationship fields.
 */
enum AggregateFunction: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Min = 'min';
    case Max = 'max';
    case Avg = 'avg';
}
