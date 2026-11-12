<?php

use Martis\Sso\SignedToken\PasetoV4Public;

// Run as `php -n`: tries the signed-token codec on a PHP that has no sodium
// extension, and prints what the person is told. Used by SsoSignedTokenProviderTest.

if (extension_loaded('sodium')) {
    echo 'SODIUM_LOADED';
    exit(0);
}

$src = dirname(__DIR__, 2).'/src/Sso';
require_once $src.'/SsoException.php';
require_once $src.'/SignedToken/Reason.php';
require_once $src.'/SignedToken/ParsedToken.php';
require_once $src.'/SignedToken/PasetoV4Public.php';

try {
    PasetoV4Public::parse('v4.public.AAAA');
    echo 'NO_EXCEPTION';
} catch (RuntimeException $e) {
    echo $e->getMessage();
}
