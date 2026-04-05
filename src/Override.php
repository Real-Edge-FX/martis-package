<?php

namespace Martis;

use Martis\Contracts\OverrideContract;

/**
 * Value object representing a page override.
 *
 * Usage in a Resource:
 *
 *     public function overrideCreate(): ?OverrideContract
 *     {
 *         return new Override('my-sidebar-form', [
 *             'width' => '480px',
 *             'position' => 'right',
 *         ]);
 *     }
 */
class Override implements OverrideContract
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        protected string $component,
        protected array $params = [],
    ) {}

    public function component(): string
    {
        return $this->component;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    /** @return array{component: string, params: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'params' => $this->params,
        ];
    }
}
