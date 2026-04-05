<?php

namespace Martis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;

/**
 * Central resolver for relatable query hooks — Nova v5 parity (REA-1144).
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
            return static::callDynamicMethod(
                $sourceResourceClass,
                $dynamicMethod,
                $request,
                $query,
                $field,
            );
        }

        // Step 2: Fall back to generic relatableQuery on TARGET resource
        return $targetResourceClass::relatableQuery($request, $query);
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
