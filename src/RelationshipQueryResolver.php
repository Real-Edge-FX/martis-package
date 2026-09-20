<?php

namespace Martis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;

/**
 * Central resolver for relatable query hooks.
 *
 * Given a source resource, a target (related) resource, and optionally a Field,
 * this resolver applies the resource-level query hooks, composed in order:
 *
 *   1. relatableQuery(Request, Builder) — the target resource's generic fence,
 *      always applied
 *   2. relatable{PluralModelName}(Request, Builder [, Field]) — the source
 *      resource's specific override, narrowing on top of (1)
 *
 * The dynamic method name uses the pluralized model class basename of the
 * RELATED resource (e.g., relatableTags for Tag model).
 *
 * When the dynamic method accepts a third parameter (FieldContract), the
 * current field instance is passed so the hook can differentiate between
 * multiple relationship fields pointing to the same target resource.
 *
 * After resource-level hooks, field-level closures are applied when the field
 * supports them (e.g. BelongsTo::relatableQueryUsing(), BelongsTo::withoutTrashed()).
 */
class RelationshipQueryResolver
{
    /**
     * Resolve and apply the appropriate relatable query hook.
     *
     * @param  class-string<\Martis\Resource>  $sourceResourceClass  The resource that owns the relationship
     * @param  class-string<\Martis\Resource>  $targetResourceClass  The related resource being queried
     * @param  Builder<Model>  $query
     * @param  FieldContract|null  $field  The relationship field (for context differentiation)
     * @return Builder<Model>
     */
    public static function resolve(
        string $sourceResourceClass,
        string $targetResourceClass,
        Request $request,
        Builder $query,
        ?FieldContract $field = null,
    ): Builder {
        // Step 1: the TARGET resource's generic relatableQuery() always runs.
        // It is the fence the target declares for every picker that reaches
        // it (typically a tenant / ownership scope on a model that cannot
        // carry a global scope), so no source resource can drop it.
        $query = $targetResourceClass::relatableQuery($request, $query);

        // Step 2: the SOURCE resource's relatable{PluralModelName}() composes
        // on top and narrows the already-fenced query for its own
        // relationships. It never replaces step 1 (it used to: an if/else
        // that let any source-side override silently reopen the target's
        // fence).
        $dynamicMethod = static::buildDynamicMethodName($targetResourceClass);

        if ($dynamicMethod !== null && method_exists($sourceResourceClass, $dynamicMethod)) {
            $query = static::callDynamicMethod(
                $sourceResourceClass,
                $dynamicMethod,
                $request,
                $query,
                $field,
            );
        }

        // Step 2b: Apply the target Resource's declarative static $with list
        // so eager-loaded display fields are available to the picker payload.
        $query = $targetResourceClass::applyWith($query);

        // Step 3: Apply field-level query modifiers (BelongsTo-specific)
        if ($field instanceof BelongsTo) {
            // Apply relatableQueryUsing closure if defined
            $closure = $field->getRelatableQueryClosure();
            if ($closure !== null) {
                $result = $closure($request, $query);
                if ($result instanceof Builder) {
                    $query = $result;
                }
            }

            // Apply withoutTrashed: exclude soft-deleted records if the model uses SoftDeletes
            if ($field->isWithoutTrashed()) {
                $model = $query->getModel();
                if (method_exists($model, 'bootSoftDeletes') || in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                    // @phpstan-ignore-next-line
                    $query->withoutTrashed();
                }
            }
        }

        return $query;
    }

    /**
     * Build the dynamic method name: relatable{PluralModelName}.
     *
     * Example: For a target resource with model App\Models\Tag,
     * returns "relatableTags".
     *
     * @param  class-string<\Martis\Resource>  $targetResourceClass
     */
    public static function buildDynamicMethodName(string $targetResourceClass): ?string
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $targetResourceClass::model();
        $basename = class_basename($modelClass);
        $plural = Str::plural($basename);

        return 'relatable'.$plural;
    }

    /**
     * Call the dynamic relatable method, passing Field as third argument
     * only when the method signature accepts it.
     *
     * @param  class-string<\Martis\Resource>  $resourceClass
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function callDynamicMethod(
        string $resourceClass,
        string $methodName,
        Request $request,
        Builder $query,
        ?FieldContract $field,
    ): Builder {
        $reflection = new \ReflectionMethod($resourceClass, $methodName);
        $paramCount = $reflection->getNumberOfParameters();

        // If method accepts 3+ params and we have a field, pass it
        if ($paramCount >= 3 && $field !== null) {
            return $resourceClass::$methodName($request, $query, $field);
        }

        // Otherwise call with standard 2 params
        return $resourceClass::$methodName($request, $query);
    }
}
