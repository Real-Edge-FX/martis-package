<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Martis\Sso\SignedToken\ConsumerSettings;
use Martis\Sso\SignedToken\IssuerSettings;
use Martis\Sso\SignedToken\PasetoV4Public;
use Martis\Sso\SignedToken\TokenIssuer;
use Martis\Sso\SsoCache;
use Martis\Sso\SsoManager;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * `martis:sso:check` (v2.23.0): validate the SSO configuration the way the
 * runtime reads it, so a bad value shows up in a deploy script instead of at
 * the first click.
 *
 * It checks the issuer block (when `martis.auth.sso.issuer.enabled` is on),
 * each enabled `signed-token` provider block and the SSO cache store, with the
 * very readers the routes use ({@see TokenIssuer::fromConfig()},
 * {@see ConsumerSettings::fromConfig()}, {@see SsoCache::store()}), and prints
 * one line per block: `ok`, or the key and the reason. Read-only: it writes
 * nothing, and a line never holds a key value or any part of one (the readers'
 * messages name the key and the type, never the value). Exit code 1 when any
 * block fails, 0 when all pass or nothing is enabled.
 *
 * Nothing here runs at boot: a bad env file must not break `config:cache`,
 * `package:discover` or a queue worker.
 */
class SsoCheckCommand extends Command
{
    protected $signature = 'martis:sso:check';

    protected $description = 'Validate the SSO issuer, the signed-token providers and the SSO cache store as the runtime reads them. Read-only.';

    private int $failures = 0;

    private int $checked = 0;

    public function handle(SsoManager $manager): int
    {
        $this->failures = 0;
        $this->checked = 0;

        $this->checkIssuer();
        $this->checkConsumers($manager);
        $this->checkCache();

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error($this->failures.' of '.$this->checked.' SSO '.($this->checked === 1 ? 'check' : 'checks').' failed.');

            return self::FAILURE;
        }

        $this->components->info($this->checked === 0
            ? 'Nothing to check: the SSO issuer and the signed-token providers are off.'
            : 'All '.$this->checked.' SSO '.($this->checked === 1 ? 'check' : 'checks').' passed.');

        return self::SUCCESS;
    }

    private function checkIssuer(): void
    {
        $label = 'issuer ['.IssuerSettings::KEY.']';

        try {
            $enabled = IssuerSettings::enabled();
        } catch (Throwable $e) {
            $this->failed($label, $e);

            return;
        }

        if (! $enabled) {
            $this->skip($label, 'off');

            return;
        }

        $this->validate($label, static fn () => TokenIssuer::fromConfig());
    }

    private function checkConsumers(SsoManager $manager): void
    {
        // The blocks the runtime builds a SignedTokenProvider for: enabled, no
        // class registered under the name, and `driver => signed-token`.
        $blocks = [];
        foreach ($manager->enabledProviders() as $name) {
            if ($manager->providerClass($name) === null && config("martis.auth.sso.providers.{$name}.driver") === 'signed-token') {
                $blocks[] = $name;
            }
        }

        if ($blocks === [] && (bool) config('martis.auth.sso.enabled', false)) {
            $this->skip('signed-token providers [martis.auth.sso.providers]', 'none enabled');

            return;
        }

        foreach ($blocks as $name) {
            $this->validate("provider [martis.auth.sso.providers.{$name}]", static function () use ($name): void {
                PasetoV4Public::ensureAvailable();
                ConsumerSettings::fromConfig($name);
            });
        }
    }

    private function checkCache(): void
    {
        $label = 'cache store ['.SsoCache::KEY.']';

        if (! SsoCache::enabled()) {
            $this->skip($label, 'SSO is off');

            return;
        }

        $this->validate($label, static fn () => SsoCache::store());
    }

    /** Run one validator and print its line. */
    private function validate(string $label, callable $validate): void
    {
        try {
            $validate();
        } catch (Throwable $e) {
            $this->failed($label, $e);

            return;
        }

        $this->checked++;
        $this->line('  <fg=green>ok</>    '.OutputFormatter::escape($label));
    }

    private function skip(string $label, string $why): void
    {
        $this->line('  <fg=gray>skip</>  '.OutputFormatter::escape($label).': '.$why);
    }

    private function failed(string $label, Throwable $e): void
    {
        $this->checked++;
        $this->failures++;

        // The messages of the readers name the key and the type of the value,
        // never the value. Any other failure shows its class only: its message
        // is not ours to vouch for.
        $reason = $e instanceof InvalidArgumentException || ($e instanceof RuntimeException && str_contains($e->getMessage(), 'ext-sodium'))
            ? $e->getMessage()
            : 'unexpected '.$e::class.' (run the sign-in path to see it)';

        $this->line('  <fg=red>FAIL</>  '.OutputFormatter::escape($label));
        $this->line('        '.OutputFormatter::escape(str_replace("\n", ' ', $reason)));
    }
}
