<?php

namespace Martis\Exceptions;

/**
 * Thrown when the authenticated user is not permitted to perform an action.
 *
 * Results in a 403 Forbidden API response.
 */
class AuthorizationException extends MartisException
{
    /** Create an authorization exception with a message and error code. */
    public function __construct(
        string $message = 'This action is unauthorized.',
        string $errorCode = 'unauthorized',
    ) {
        parent::__construct($message, $errorCode, [], 403);
    }

    /**
     * Create an exception for a specific policy action.
     */
    public static function forAction(string $action, string $resource = ''): self
    {
        $message = $resource
            ? "You are not authorized to {$action} this {$resource}."
            : "You are not authorized to {$action}.";

        return new self($message, 'unauthorized_action');
    }
}
