<?php

namespace Martis\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Contract for all Martis Resource classes.
 *
 * A Resource is the central abstraction in Martis — it wraps an Eloquent model
 * and declares its Fields, authorization rules, and display metadata.
 *
 * Every custom admin resource MUST implement this interface. It mirrors
 * the interface exposed by Laravel Nova v5 resources so that migration
 * is straightforward.
 *
 * TypeScript generation strategy (PHP → TS):
 *   Run `php artisan martis:ts-types` to emit `resources/js/types/resources.d.ts`.
 *   That command inspects each registered Resource, calls `fields()` with a
 *   synthetic Request, and derives TypeScript interfaces from `FieldContract::type()`
 *   and `FieldContract::toArray()`. The generated file is committed to source
 *   control and consumed by the React frontend.
 */
interface ResourceContract
{
    /**
     * Return the fields that belong to this resource.
     *
     * @return list<FieldContract>
     */
    public function fields(Request $request): array;

    /**
     * Return the Eloquent model class name associated with this resource.
     */
    public static function model(): string;

    /**
     * Return a fresh (unsaved) instance of the associated model.
     */
    public static function newModel(): Model;

    /**
     * Return the URL key used in route segments (e.g. "posts", "users").
     */
    public static function uriKey(): string;

    /**
     * Return the plural human-readable label (e.g. "Blog Posts").
     */
    public static function label(): string;

    /**
     * Return the singular human-readable label (e.g. "Blog Post").
     */
    public static function singularLabel(): string;

    /**
     * Determine whether the current user may view any resource of this type.
     */
    public function authorizedToViewAny(Request $request): bool;

    /**
     * Determine whether the current user may view this specific resource.
     */
    public function authorizedToView(Request $request): bool;

    /**
     * Determine whether the current user may create resources of this type.
     */
    public function authorizedToCreate(Request $request): bool;

    /**
     * Determine whether the current user may update this resource.
     */
    public function authorizedToUpdate(Request $request): bool;

    /**
     * Determine whether the current user may delete this resource.
     */
    public function authorizedToDelete(Request $request): bool;
}
