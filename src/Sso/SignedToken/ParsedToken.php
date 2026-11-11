<?php

declare(strict_types=1);

namespace Martis\Sso\SignedToken;

/**
 * A v4.public token split into its parts and not yet verified: nothing in it
 * is trustworthy until {@see PasetoV4Public::verify()} accepted the signature.
 */
final class ParsedToken
{
    public function __construct(
        public readonly string $message,
        public readonly string $signature,
        public readonly string $footer,
    ) {}
}
