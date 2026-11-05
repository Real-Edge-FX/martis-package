<?php

namespace Martis\Fields\Concerns;

use Illuminate\Database\Eloquent\Model;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * The title a relation field shows for a related record when the field does
 * not set `titleAttribute()` itself: the related resource's `title()`, as
 * Nova shows a BelongsTo by its related resource's title. Before v2.17.0 the
 * field read its own default (`name`) and ignored the related resource, so a
 * model without a `name` column showed `#4` in the cell while the picker,
 * which reads `_title`, showed the right label.
 *
 * The related resource is deferred to only when it declares a title of its
 * own (a `titleAttribute()` other than the base `id`, or an overridden
 * `title()`): a resource left on the base default keeps the field's `name`,
 * which is what it showed before. An explicit `titleAttribute()` (or
 * `displayColumn()`) on the field always wins.
 *
 * The using class has a `$titleAttribute` string property and calls
 * `markTitleAttributeExplicit()` from its `titleAttribute()` setter.
 */
trait DefersTitleToRelatedResource
{
    protected bool $titleAttributeExplicit = false;

    protected function markTitleAttributeExplicit(): void
    {
        $this->titleAttributeExplicit = true;
    }

    /**
     * The related resource class to defer the title to, or null when the
     * field keeps its own `titleAttribute`.
     *
     * @param  class-string<resource>|null  $resourceClass
     * @return class-string<resource>|null
     */
    protected function titleResourceClass(?string $resourceClass): ?string
    {
        if ($this->titleAttributeExplicit || $resourceClass === null || ! is_subclass_of($resourceClass, Resource::class)) {
            return null;
        }

        $declaresTitle = $resourceClass::titleAttribute() !== 'id'
            || (new \ReflectionMethod($resourceClass, 'title'))->getDeclaringClass()->getName() !== Resource::class;

        return $declaresTitle ? $resourceClass : null;
    }

    /**
     * The related resource registered under a URI key, or null when the key
     * is null or unknown (a field that names a wrong key fails elsewhere).
     *
     * @return class-string<resource>|null
     */
    protected function resourceClassForUriKey(?string $uriKey): ?string
    {
        if ($uriKey === null) {
            return null;
        }

        $registry = app(ResourceRegistry::class);

        return $registry->has($uriKey) ? $registry->get($uriKey) : null;
    }

    /**
     * The title of `$related`: its resource's `title()` when the field
     * defers to it, else the field's own `titleAttribute`.
     *
     * @param  class-string<resource>|null  $resourceClass
     */
    protected function relatedTitle(Model $related, ?string $resourceClass): mixed
    {
        $deferTo = $this->titleResourceClass($resourceClass);

        return $deferTo !== null
            ? (new $deferTo($related))->title()
            : $related->getAttribute($this->titleAttribute);
    }

    /**
     * The `titleAttribute` the schema serializes for the picker: null when
     * the field defers to the related resource, so the picker labels a row
     * with its `_title` (that resource's `title()`), as the cell does.
     *
     * @param  class-string<resource>|null  $resourceClass
     */
    protected function serializedTitleAttribute(?string $resourceClass): ?string
    {
        return $this->titleResourceClass($resourceClass) !== null ? null : $this->titleAttribute;
    }
}
