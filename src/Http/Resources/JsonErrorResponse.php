<?php

namespace Martis\Http\Resources;

use Illuminate\Http\JsonResponse as IlluminateJsonResponse;

/**
 * Standard JSON error response for Martis API endpoints.
 *
 * Provides three named constructors that cover the most common error scenarios:
 *   - `validation()` — 422 with per-field error details
 *   - `notFound()`   — 404 with a human-readable message
 *   - `serverError()` — 500 with a generic message (no internal details leaked)
 *
 * Shape (422):
 * ```json
 * {
 *   "message": "The given data was invalid.",
 *   "errors": [
 *     { "field": "email", "message": "The email field is required.", "code": "required" }
 *   ]
 * }
 * ```
 *
 * Shape (404/500):
 * ```json
 * {
 *   "message": "Resource not found.",
 *   "errors": []
 * }
 * ```
 */
final class JsonErrorResponse
{
    /**
     * @param  list<array{field: string|null, message: string, code: string}>  $errors
     */
    public function __construct(
        private readonly string $message,
        private readonly array $errors,
        private readonly int $status,
    ) {}

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /**
     * Build a 422 Unprocessable Entity response from a map of validation errors.
     *
     * $fieldErrors format: `['email' => ['The email is required.', ...], ...]`
     *
     * @param  array<string, list<string>>  $fieldErrors
     */
    public static function validation(array $fieldErrors, string $message = 'The given data was invalid.'): self
    {
        $errors = [];

        foreach ($fieldErrors as $field => $messages) {
            foreach ($messages as $message_text) {
                $errors[] = [
                    'field' => $field,
                    'message' => $message_text,
                    'code' => self::inferCode($message_text),
                ];
            }
        }

        return new self($message, $errors, 422);
    }

    /**
     * Build a 404 Not Found response.
     */
    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self($message, [], 404);
    }

    /**
     * Build a 500 Internal Server Error response.
     * Never exposes internal error details to the client.
     */
    public static function serverError(string $message = 'An unexpected error occurred.'): self
    {
        return new self($message, [], 500);
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * @return array{message: string, errors: list<array{field: string|null, message: string, code: string}>}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'errors' => $this->errors,
        ];
    }

    /**
     * Convert to an Illuminate JSON response with the configured HTTP status.
     */
    public function toResponse(): IlluminateJsonResponse
    {
        return new IlluminateJsonResponse($this->toArray(), $this->status);
    }

    /**
     * Return the HTTP status code.
     */
    public function status(): int
    {
        return $this->status;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Infer a machine-readable error code from a human-readable message.
     * This is a best-effort heuristic; callers can override by building
     * error items directly.
     */
    private static function inferCode(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'required')) {
            return 'required';
        }

        if (str_contains($lower, 'unique') || str_contains($lower, 'already')) {
            return 'unique';
        }

        if (str_contains($lower, 'email')) {
            return 'email';
        }

        if (str_contains($lower, 'min') || str_contains($lower, 'at least')) {
            return 'min';
        }

        if (str_contains($lower, 'max') || str_contains($lower, 'may not')) {
            return 'max';
        }

        return 'invalid';
    }
}
