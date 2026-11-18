<?php

namespace Martis\Exceptions;

use Illuminate\Auth\Access\AuthorizationException as LaravelAuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as LaravelHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Martis\Http\Resources\JsonErrorResponse;
use Throwable;

/**
 * Martis exception handler mixin.
 *
 * Provides a static method that can be called from the application's
 * exception handler to map Martis exceptions to structured JSON responses.
 *
 * Usage in app/Exceptions/Handler.php:
 *
 * ```php
 * public function render($request, Throwable $e): Response
 * {
 *     if ($response = MartisExceptionHandler::render($request, $e)) {
 *         return $response;
 *     }
 *     return parent::render($request, $e);
 * }
 * ```
 */
final class Handler
{
    /**
     * Map a Martis exception to a JSON response, or return null if not applicable.
     */
    public static function render(Request $request, Throwable $e): ?JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthorizationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 403);
        }

        if ($e instanceof ResourceNotFoundException) {
            return JsonErrorResponse::notFound($e->getMessage())->toResponse();
        }

        if ($e instanceof ThrottleRequestsException) {
            // Localise the default "Too Many Attempts." message and respect
            // the Retry-After header Laravel already set on the exception.
            $message = trans('martis::messages.error_throttled');
            if ($message === 'martis::messages.error_throttled') {
                $message = $e->getMessage();
            }

            return response()->json([
                'message' => $message,
                'errors' => [],
            ], 429, $e->getHeaders());
        }

        if ($e instanceof MartisException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'code' => $e->errorCode(),
            ], $e->httpStatus());
        }

        return null;
    }

    /**
     * The answer a catch-all of the Martis API gives a user-facing
     * throwable, or null when it has none.
     *
     * This is `render()` plus Laravel's own typed exceptions, which a
     * consumer hook or a model event listener throws as readily as Martis'
     * ones. Outside a catch-all Laravel renders those itself (a web form
     * redirects back with its errors, a host API answers in its own
     * shape), so they are deliberately **not** in `render()`, which a host
     * application is invited to call from its own exception handler. Only
     * the guards that would otherwise flatten them to a 500 — the delete
     * and restore endpoints — call this.
     */
    public static function renderUserFacing(Request $request, Throwable $e): ?JsonResponse
    {
        if ($e instanceof LaravelValidationException) {
            // Laravel keys its errors by field; the panel reads the list of
            // `{field, message, code}` every other Martis 422 answers, so
            // the map is converted rather than passed through.
            return JsonErrorResponse::validation($e->errors(), $e->getMessage())->toResponse();
        }

        if ($e instanceof LaravelAuthorizationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
            ], 403);
        }

        return self::render($request, $e);
    }

    /**
     * Whether an exception carries a message written for the user, so a
     * catch-all of the API may return it to the client as it is.
     *
     * `UserFacingException` is the one a hook throws for a plain message;
     * the typed validation, authorization and not-found exceptions are
     * authored for the response as well. Every other throwable (a runtime,
     * database, storage or driver error) is internal: its message can hold
     * a path, a class name or a query, so the client gets a generic message
     * and the details stay in the log.
     */
    public static function isUserFacing(Throwable $e): bool
    {
        return $e instanceof UserFacingException
            || $e instanceof ValidationException
            || $e instanceof AuthorizationException
            || $e instanceof ResourceNotFoundException
            || $e instanceof LaravelValidationException
            || $e instanceof LaravelAuthorizationException;
    }

    /**
     * Register Martis exception handling on a Laravel exception handler.
     *
     * Call this from MartisServiceProvider::boot() to auto-register.
     * Compatible with Laravel 10+ renderable() API.
     */
    public static function register(LaravelExceptionHandler $handler): void
    {
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        /** @var LaravelHandler $handler */
        $handler->renderable(function (MartisException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return self::render($request, $e);
        });

        // Localise Laravel's throttle message for API clients (pt_PT/pt_BR).
        $handler->renderable(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return self::render($request, $e);
        });
    }
}
