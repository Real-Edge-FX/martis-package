<?php

namespace Martis\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Contract for the self-service registration flow.
 *
 * Martis ships `Martis\Auth\DefaultRegistersUsers`, which validates the
 * request, creates a user via the configured Eloquent model, optionally
 * assigns a default role, fires `Illuminate\Auth\Events\Registered`, and
 * returns the new user.
 *
 * Consumers override the binding in their service provider to take full
 * control over the create-user pipeline:
 *
 * ```php
 * // App\Providers\MartisServiceProvider::register()
 * $this->app->bind(
 *     \Martis\Contracts\RegistersUsers::class,
 *     \App\Auth\MyRegistrar::class,
 * );
 * ```
 *
 * The Martis-shipped `AuthController::register()` resolves this binding
 * to handle `POST /{martis-path}/api/auth/register`.
 */
interface RegistersUsers
{
    /**
     * Validate the incoming request, create a user, and return it.
     *
     * Implementations are free to throw a `ValidationException` to surface
     * a 422 response shape Martis's React form already understands
     * (`{ message, errors: { field: [msg] } }`).
     */
    public function register(Request $request): Authenticatable;
}
