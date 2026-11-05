<?php

namespace Martis\Support;

use Martis\Enums\SortDirection;

/**
 * One key of a list's ordering: an attribute and a direction. The unit of
 * `?sort=a:asc,b:desc`, of `Resource::defaultSort()` and of the schema's
 * `defaultSort` payload (see `ListSort`).
 */
final readonly class SortKey
{
    public function __construct(
        public string $attribute,
        public SortDirection $direction = SortDirection::Asc,
    ) {}

    /** @return array{attribute: string, direction: string} */
    public function toArray(): array
    {
        return ['attribute' => $this->attribute, 'direction' => $this->direction->value];
    }
}
