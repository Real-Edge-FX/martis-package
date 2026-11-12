<?php

declare(strict_types=1);

namespace Martis\Auth;

use InvalidArgumentException;

/**
 * A `martis.auth.*` value that is set but unusable (v2.21.0). The message
 * names the config key, so the app fails loudly instead of falling back.
 */
final class AuthConfigurationException extends InvalidArgumentException
{
    public static function forKey(string $key, string $problem): self
    {
        return new self("Config [{$key}] {$problem}");
    }
}
