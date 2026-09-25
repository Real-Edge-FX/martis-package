<?php

namespace Martis\Auth;

use InvalidArgumentException;

/**
 * `martis.auth.passwordReset.broker` names no password broker, or one that
 * does not read the Martis guard's users, or none of `config/auth.php`
 * reads them. See {@see GuardCatalog::martisPasswordBroker()}.
 */
class PasswordBrokerConfigurationException extends InvalidArgumentException {}
