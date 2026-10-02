<?php

namespace Martis\Exceptions;

use Throwable;

/**
 * An error whose message is written for the person using the panel.
 *
 * A hook or listener that has to stop a request for a reason the user can
 * act on (`beforeDelete()` refusing a protected record, an observer that
 * will not let a record go) throws this exception. Its message is the only
 * kind a catch-all of the API returns to the client as it is, with the
 * exception's HTTP status (422 by default).
 *
 * Any other exception (a `RuntimeException`, a database, storage or driver
 * error) is internal: the client gets a generic message and the details go
 * to the log and to `report()`, unless `app.debug` is on. Never put an
 * internal detail (a path, a class name, a query, a bucket) in the message
 * of this exception, the user sees it.
 */
class UserFacingException extends MartisException
{
    /**
     * @param  string  $message  Shown to the user as it is.
     * @param  int  $httpStatus  Status of the JSON response (422 by default).
     * @param  string  $errorCode  Machine-readable code (snake_case).
     * @param  array<string, mixed>  $context  Structured data for logging, never sent to the client.
     */
    public function __construct(
        string $message,
        int $httpStatus = 422,
        string $errorCode = 'user_facing',
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $context, $httpStatus, $previous);
    }
}
