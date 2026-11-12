<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Martis\Sso\SignedToken\PasetoV4Public;

/**
 * `martis:sso:keys` — print a new Ed25519 key pair for the signed-token SSO
 * (v2.21.0), base64, with where each half goes.
 *
 * It writes nothing: not to `.env`, not to a config file, not to a log. The
 * secret key is printed once to the terminal; whoever runs the command puts
 * it in the issuing app's environment.
 */
class SsoKeysCommand extends Command
{
    protected $signature = 'martis:sso:keys';

    protected $description = 'Print a new Ed25519 key pair (base64) for the signed-token SSO. Writes nothing.';

    public function handle(): int
    {
        PasetoV4Public::ensureAvailable();

        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = sodium_crypto_sign_publickey($pair);

        $this->components->info('New Ed25519 key pair for the signed-token SSO. Nothing was written anywhere.');

        $this->newLine();
        $this->line('<fg=cyan>Secret key</> goes ONLY in the environment of the app that ISSUES tokens (the panel whose users sign in elsewhere).');
        $this->line('Do not commit it, and keep this output out of shell history and CI logs:');
        $this->newLine();
        $this->line('  MARTIS_SSO_ISSUER_PRIVATE_KEY='.$secret);
        $this->newLine();

        $this->line('<fg=cyan>Public key</> goes in the app that VERIFIES tokens, in its provider block: martis.auth.sso.providers.<name>.public_keys');
        $this->line('(or its MARTIS_SSO_<NAME>_PUBLIC_KEYS, comma separated). It is not secret.');
        $this->newLine();
        $this->line('  public key:');
        $this->line('  '.base64_encode($public));
        $this->newLine();
        $this->line('  kid: '.PasetoV4Public::keyId($public));
        $this->newLine();

        $this->line('Rotation: generate a second pair, add its public key to <fg=cyan>public_keys</> next to the old one,');
        $this->line('switch the issuer to the new secret key, then remove the old public key once the old tokens are past');
        $this->line('their short life (max_age_seconds plus the clock skew).');

        return self::SUCCESS;
    }
}
