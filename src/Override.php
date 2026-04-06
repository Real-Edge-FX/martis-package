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
 *         return (new Override('my-sidebar-form'))
 *             ->width('480px')
 *             ->redirectAfter(RedirectAfter::INDEX);
 *     }
 *
 * Custom URL with placeholders:
 *
 *     return (new Override('my-component'))
 *         ->redirectAfter('/resources/{resource}/{id}/preview');
 */
class Override implements OverrideContract
{
    protected ?string $redirectAfterValue = null;

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

    /**
     * Set where to redirect after a successful action.
     *
     * Accepts a RedirectAfter enum for predefined targets,
     * or a string URL with {id} and {resource} placeholders.
     */
    public function redirectAfter(RedirectAfter|string $target): static
    {
        $this->redirectAfterValue = $target instanceof RedirectAfter
            ? $target->value
            : $target;

        return $this;
    }

    public function getRedirectAfter(): ?string
    {
        return $this->redirectAfterValue;
    }

    /**
     * Convenience setter for the width param (common for sidebar overrides).
     */
    public function width(string $width): static
    {
        $this->params['width'] = $width;

        return $this;
    }

    /** {@inheritDoc} */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'params' => $this->params,
            'redirectAfter' => $this->redirectAfterValue,
        ];
    }
}
