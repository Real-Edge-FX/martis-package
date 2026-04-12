<?php

namespace Martis;

use Martis\Contracts\OverrideContract;

/**
 * Generic value object representing a page override.
 *
 * Use this class for custom (non-core) override components.
 * For core components, use the typed subclasses:
 *   - DrawerOverride — sidebar/drawer form
 *   - ModalOverride  — centered dialog (future)
 *
 * Usage:
 *
 *     public function overrideCreate(): ?OverrideContract
 *     {
 *         return (new Override('my-custom-component', [
 *             'showTimeline' => true,
 *         ]))->redirectAfter(RedirectAfter::INDEX);
 *     }
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

    /**
     * Component.
     */
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

    /**
     * Get redirect after.
     */
    public function getRedirectAfter(): ?string
    {
        return $this->redirectAfterValue;
    }

    /** @return array{component: string, params: array<string, mixed>, redirectAfter: string|null} */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'params' => $this->params,
            'redirectAfter' => $this->redirectAfterValue,
        ];
    }
}
