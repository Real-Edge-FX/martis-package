<?php

declare(strict_types=1);

namespace Martis\Impersonation;

use RuntimeException;

/**
 * A deliberate refusal of {@see ImpersonationManager::start()}: disabled,
 * no operator, already active, self, or a target that may not be
 * impersonated. Its message is written for the client. Any other exception
 * raised while starting (a database or session error) is not a refusal and
 * must never reach the response body.
 */
final class ImpersonationRefusedException extends RuntimeException {}
