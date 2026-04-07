<?php

namespace Martis\Actions;

use Illuminate\Support\Collection;

/**
 * Container for action field values submitted by the user.
 *
 * Nova v5 parity: ActionFields allows dynamic property access to field values.
 * Example: $fields->subject, $fields->message
 */
class ActionFields
{
    /** @var array<string, mixed> */
    private array $attributes;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Create from request data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data): self
    {
        return new self($data);
    }

    /** Get a field value by attribute name. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Dynamic property access for field values. */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /** Check if a field value exists. */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * Get all field values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Convert to Collection.
     *
     * @return Collection<string, mixed>
     */
    public function toCollection(): Collection
    {
        return new Collection($this->attributes);
    }
}
