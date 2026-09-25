<?php

namespace Martis\Fields\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Keeps Create off the panel of a Through relationship.
 *
 * `HasManyThrough` and `HasOneThrough` use it. A Through relationship is a
 * traversal with no direct foreign key to populate (the intermediate model
 * is ambiguous), so, as in Nova, its panel never offers Create and the
 * controllers refuse one (403). `canCreate()` stays callable, so a resource
 * that calls it still loads, and `canCreate(true)` logs a warning naming
 * the field, once per field and request (fields() runs on every request).
 */
trait IgnoresCreateThrough
{
    /** @var array<string, true> Warnings already logged when there is no request. */
    private array $createThroughWarned = [];

    /**
     * No effect: the panel of a Through relationship never offers Create.
     * Asking for it logs a warning (`Log::warning`, on the default channel).
     */
    public function canCreate(bool $value = true): static
    {
        if ($value && $this->firstCreateThroughWarning()) {
            Log::warning(sprintf(
                'Martis: %s::canCreate() on "%s" has no effect: records cannot be created through a Through relationship, as in Nova. Remove the call.',
                class_basename(static::class),
                $this->relationship,
            ), [
                'field' => static::class,
                'relationship' => $this->relationship,
            ]);
        }

        return $this;
    }

    /**
     * Whether this is the first warning for this field in the request, and
     * record it. The request remembers it, so every instance built in the
     * request shares it and a fresh request (Octane) warns again; with no
     * request (a queue job, a raw script) this field instance does.
     */
    private function firstCreateThroughWarning(): bool
    {
        // Without an application (unit tests, raw scripts) there is no log.
        if (! app()->bound('log')) {
            return false;
        }

        $key = static::class.'@'.$this->relationship;
        $request = $this->safeRequest();

        if ($request === null) {
            if (isset($this->createThroughWarned[$key])) {
                return false;
            }

            $this->createThroughWarned[$key] = true;

            return true;
        }

        /** @var array<string, true> $warned */
        $warned = $request->attributes->get('martis.through_can_create_warned', []);

        if (isset($warned[$key])) {
            return false;
        }

        $warned[$key] = true;
        $request->attributes->set('martis.through_can_create_warned', $warned);

        return true;
    }
}
