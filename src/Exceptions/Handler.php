<?php

namespace Martis\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as LaravelHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
