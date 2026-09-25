<?php

namespace Martis\Fields\Concerns;

use Illuminate\Http\Request;
use Martis\ResourceRegistry;

/**
 * Hides a relationship field from a user who may not list its related
 * resource.
 *
 * `HasMany`, `HasOne`, `MorphMany`, `MorphOne`, `BelongsToMany`,
 * `MorphToMany` (and the fields built on them: `HasManyThrough`,
 * `HasOneThrough`, `HasOneOfMany`, `MorphOneOfMany`) use it. As in Nova,
 * whose relationship fields authorize with
 * `$resourceClass::authorizedToViewAny($request) && parent::authorize($request)`,
 * the field is seen only when the related resource's `viewAny` passes and
 * its own `canSee()` does: the detail page leaves the panel out and the
 * relationship routes answer 403 (Nova's relationship index is the related
 * resource's index, which aborts with 403 without `viewAny`).
 *
 * A related resource that is not registered does not hide the field: the
 * relationship routes answer 404 for it anyway.
 */
trait AuthorizesRelatedResource
{
    /** {@inheritdoc} */
    public function isAuthorizedToSee(Request $request): bool
    {
        return $this->relatedResourceAuthorizedToViewAny($request)
            && $this->isAuthorizedToSeeIgnoringRelatedResource($request);
    }

    /**
     * The field's own `canSee()` decision, without the related resource's
     * `viewAny`.
     */
    public function isAuthorizedToSeeIgnoringRelatedResource(Request $request): bool
    {
        return parent::isAuthorizedToSee($request);
    }

    /**
     * Whether the user may list the related resource (its `viewAny`).
     */
    public function relatedResourceAuthorizedToViewAny(Request $request): bool
    {
        $key = $this->getRelatedResourceKey();

        if ($key === null || ! app()->bound(ResourceRegistry::class)) {
            return true;
        }

        $registry = app(ResourceRegistry::class);

        if (! $registry->has($key)) {
            return true;
        }

        $resourceClass = $registry->get($key);

        return (new $resourceClass)->authorizedToViewAny($request);
    }
}
