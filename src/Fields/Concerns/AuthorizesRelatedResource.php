<?php

namespace Martis\Fields\Concerns;

use Illuminate\Http\Request;
use Martis\Gates\SoftGate;
use Martis\Resource;
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
     * Whether the user may list the related resource (its `viewAny`) and is
     * not soft-locked from it (`lockedFor()`, `requirePlan()`): a panel lists
     * the related resource's records, so a lock closes it as a denied
     * `viewAny` does.
     */
    public function relatedResourceAuthorizedToViewAny(Request $request): bool
    {
        $related = $this->relatedResourceInstance();

        return $related === null || ($related->authorizedToViewAny($request) && ! SoftGate::isLocked($related, $request));
    }

    /**
     * The lock payload of the related resource for a user who may list it
     * (`viewAny`) but is soft-locked from it, or `null`: the relationship
     * routes answer it as every endpoint of the locked resource does (`403`
     * with `locked` and `lock`). `viewAny` wins, as everywhere: a user who may
     * not list the related resource is not told what a plan would unlock.
     *
     * @return array<string, mixed>|null
     */
    public function relatedResourceLock(Request $request): ?array
    {
        $related = $this->relatedResourceInstance();

        return $related !== null && $related->authorizedToViewAny($request) ? SoftGate::lockOf($related, $request) : null;
    }

    /** The related resource, or null when it is not registered. */
    private function relatedResourceInstance(): ?Resource
    {
        $key = $this->getRelatedResourceKey();

        if ($key === null || ! app()->bound(ResourceRegistry::class)) {
            return null;
        }

        $registry = app(ResourceRegistry::class);

        if (! $registry->has($key)) {
            return null;
        }

        $resourceClass = $registry->get($key);

        return new $resourceClass;
    }
}
