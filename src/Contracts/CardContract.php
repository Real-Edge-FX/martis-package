<?php

namespace Martis\Contracts;

/**
 * Contract for card descriptors.
 *
 * Cards are the smallest unit advertised by a Resource on its detail
 * surface. Every concrete card class (custom cards, metric placeholders,
 * future widget types) implements this contract so the schema endpoint
 * can serialize them without knowing their concrete shape. Implementing
 * classes carry their own rendering logic, but the metadata surface
 * (name, uriKey, component, meta) is fixed here.
 */
interface CardContract
{
    /**
     * Build a new card descriptor.
     *
     * @param  string  $name  Human-readable label used as the card title.
     * @param  string|null  $uriKey  Stable identifier used in URLs and the
     *                               component registry. Falls back to a
     *                               kebab-case slug derived from `$name`.
     */
    public static function make(string $name, ?string $uriKey = null): static;

    /**
     * Return the card title shown to the end user.
     */
    public function name(): string;

    /**
     * Return the stable identifier addressed by URLs and the component
     * registry. Stays constant across re-renders of the same card.
     */
    public function uriKey(): string;

    /**
     * Return the registered component key the frontend resolves to render
     * this card, or `null` to fall back to the bundled default.
     */
    public function component(): ?string;

    /**
     * Return the free-form payload the frontend forwards to the resolved
     * component. Use it for card-specific configuration that the schema
     * surface itself does not standardise.
     *
     * @return array<string, mixed>
     */
    public function meta(): array;

    /**
     * Serialize the card into the schema envelope returned by the
     * resource endpoint.
     *
     * @return array{type: string, name: string, uriKey: string, component: string|null, meta: array<string, mixed>}
     */
    public function toArray(): array;
}
