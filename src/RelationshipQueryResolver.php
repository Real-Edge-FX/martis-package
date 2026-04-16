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
 * Central resolver for relatable query hooks — Nova v5 parity.
 *
 * Given a source resource, a target (related) resource, and optionally a Field,
 * this resolver determines which query hook to call:
 *
 *   1. relatable{PluralModelName}(Request, Builder [, Field]) — specific override
 *   2. relatableQuery(Request, Builder) — generic fallback on the target resource
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
        // Step 1: Try dynamic method relatable{PluralModelName} on SOURCE resource
        $dynamicMethod = static::buildDynamicMethodName($targetResourceClass);

        if ($dynamicMethod !== null && method_exists($sourceResourceClass, $dynamicMethod)) {
            $query = static::callDynamicMethod(
                $sourceResourceClass,
                $dynamicMethod,
                $request,
                $query,
                $field,
            );
        } else {
            // Step 2: Fall back to generic relatableQuery on TARGET resource
            $query = $targetResourceClass::relatableQuery($request, $query);
        }

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
