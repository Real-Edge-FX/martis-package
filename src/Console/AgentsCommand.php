<?php

declare(strict_types=1);

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Martis\Support\AgentDetector;
use Martis\Support\AgentProfile;
use Martis\Support\EnvFilePatcher;
use Martis\Support\McpConfigPatcher;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * `martis:agents` — generate guidelines for AI coding agents and
 * optionally wire the Martis MCP server into the agent's config.
 *
 * Standalone command, idempotent. Re-running with the same flags
 * produces a zero diff. The guideline content is the canonical
 * `stubs/agents/AGENTS.md.stub`, rendered with the project's name
 * and namespace; the MCP section is conditional on whether the user
 * opted into wiring the MCP server.
 *
 * Lifecycle scenarios live in the package docs at
 * `docs/agent-guidelines.md`.
 */
class AgentsCommand extends Command
{
    protected $signature = 'martis:agents
        {--agent=* : Restrict to specific agents (claude, cursor, gemini, codex, copilot)}
        {--with-mcp : Wire the Martis MCP server in the agent\'s config without prompting}
        {--without-mcp : Skip the MCP wiring entirely}
        {--mcp-only : Only patch the MCP config + .env; do not touch guideline files}
        {--mcp-unwire : Remove the Martis MCP entry + env var; do not touch guideline files}
        {--force : Overwrite existing guideline files without prompting}
        {--dry-run : Print the actions instead of executing them}';

    protected $description = 'Generate guidelines for AI coding agents (CLAUDE.md, AGENTS.md, ...) and optionally wire the Martis MCP server.';

    public function handle(): int
    {
        $base = base_path();
        $detector = new AgentDetector($base);
        $mcp = new McpConfigPatcher($base);
        $env = new EnvFilePatcher($base);

        $profiles = $this->resolveProfiles($detector);
        if ($profiles === []) {
            $this->components->warn('No agent selected; nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('mcp-unwire')) {
            return $this->runUnwire($profiles, $mcp, $env);
        }

        $wireMcp = $this->resolveMcpChoice($profiles);

        if (! $this->option('mcp-only')) {
            $this->writeGuidelines($profiles, $wireMcp);
        }

        if ($wireMcp) {
            $this->wireMcp($profiles, $mcp, $env);
        }

        $this->components->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return list<AgentProfile>
     */
    private function resolveProfiles(AgentDetector $detector): array
    {
        $explicit = (array) $this->option('agent');
        if ($explicit !== []) {
            $picked = [];
            foreach ($explicit as $key) {
                $profile = $detector->profileByKey((string) $key);
                if ($profile === null) {
                    $this->components->warn("Unknown agent: {$key}");

                    continue;
                }
                $picked[] = $profile;
            }

            return $picked;
        }

        $detected = $detector->detect();
        if ($this->option('no-interaction')) {
            return $detected;
        }

        $candidates = $detected !== [] ? $detected : $detector->profiles();
        $labels = [];
        foreach ($candidates as $profile) {
            $labels[$profile->key] = $profile->label;
        }

        $defaults = array_map(static fn (AgentProfile $p): string => $p->key, $detected);
        $selected = multiselect(
            label: $detected === []
                ? 'No agents detected. Pick which to set up:'
                : 'Detected agents. Confirm or adjust the selection:',
            options: $labels,
            default: $defaults,
            required: false,
        );

        $out = [];
        foreach ($selected as $key) {
            $profile = $detector->profileByKey((string) $key);
            if ($profile !== null) {
                $out[] = $profile;
            }
        }

        return $out;
    }

    /**
     * @param  list<AgentProfile>  $profiles
     */
    private function resolveMcpChoice(array $profiles): bool
    {
        if ($this->option('without-mcp') || $this->option('mcp-unwire')) {
            return false;
        }
        if ($this->option('with-mcp') || $this->option('mcp-only')) {
            return true;
        }
        $eligible = array_values(array_filter($profiles, static fn (AgentProfile $p): bool => $p->supportsMcp()));
        if ($eligible === []) {
            return false;
        }
        if ($this->option('no-interaction')) {
            return false;
        }

        return confirm('Wire the Martis MCP server into the selected agents?', default: true);
    }

    /**
     * @param  list<AgentProfile>  $profiles
     */
    private function writeGuidelines(array $profiles, bool $withMcp): void
    {
        $stubPath = $this->stubPath();
        if (! is_file($stubPath)) {
            $this->components->error("Stub not found at {$stubPath}.");

            return;
        }

        $rendered = $this->render((string) file_get_contents($stubPath), $withMcp);

        $targets = ['AGENTS.md'];
        foreach ($profiles as $profile) {
            if ($profile->guidelineFile !== 'AGENTS.md') {
                $targets[] = $profile->guidelineFile;
            }
        }
        $targets = array_values(array_unique($targets));

        foreach ($targets as $relative) {
            $absolute = base_path().'/'.$relative;
            $exists = file_exists($absolute);
            if ($exists && ! $this->option('force') && ! $this->option('no-interaction')) {
                $confirmed = confirm("`{$relative}` already exists. Overwrite?", default: false);
                if (! $confirmed) {
                    $this->components->info("Skipped {$relative}.");

                    continue;
                }
            }

            if ($this->option('dry-run')) {
                $this->components->info('[dry-run] write '.$relative.' ('.strlen($rendered).' bytes)');

                continue;
            }

            $dir = dirname($absolute);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($absolute, $rendered);
            $this->components->info(($exists ? 'Updated' : 'Wrote').' '.$relative);
        }
    }

    /**
     * @param  list<AgentProfile>  $profiles
     */
    private function wireMcp(array $profiles, McpConfigPatcher $mcp, EnvFilePatcher $env): void
    {
        $entry = $this->mcpEntry();

        foreach ($profiles as $profile) {
            if (! $profile->supportsMcp()) {
                $this->components->warn("MCP wiring not supported for {$profile->label}; skipping.");

                continue;
            }
            if ($this->option('dry-run')) {
                $this->components->info('[dry-run] patch MCP config for '.$profile->label.' at '.$profile->mcpConfigFile);

                continue;
            }
            $written = $mcp->patch($profile, $entry);
            $this->components->info("Wired MCP server in {$written}");
        }

        if ($this->option('dry-run')) {
            $this->components->info('[dry-run] write full MCP env block to .env and .env.example');

            return;
        }

        $this->writeMcpEnvBlock($env);
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpEntry(): array
    {
        // Scaffold-time default: http. The package config no longer
        // forces a fallback ('transport' = env('MARTIS_MCP_TRANSPORT')
        // with no default), so when the operator has not pinned a
        // transport in their .env, this command treats the
        // recommended setup (a long-running HTTP server) as default
        // and writes the matching URL entry into .mcp.json.
        // McpServeCommand falls back to 'stdio' under the same null
        // value so existing-consumer behaviour is preserved.
        $transport = strtolower((string) (config('martis.mcp.transport') ?: 'http'));

        if ($transport === 'http') {
            $url = (string) config('martis.mcp.url', '');
            if ($url === '') {
                $host = (string) config('martis.mcp.host', '127.0.0.1');
                if ($host === '0.0.0.0' || $host === '::') {
                    $host = 'localhost';
                }
                $port = (int) config('martis.mcp.port', 8091);
                $path = (string) config('martis.mcp.path', '/mcp');
                $url = "http://{$host}:{$port}{$path}";
            }

            return [
                'type' => 'http',
                'url' => $url,
            ];
        }

        return [
            'command' => 'php',
            'args' => ['artisan', 'martis:mcp-serve'],
            'cwd' => base_path(),
        ];
    }

    private function writeMcpEnvBlock(EnvFilePatcher $env): void
    {
        $entries = [
            ['MARTIS_MCP_ENABLED', 'true', 'Set to false to disable the Martis MCP server without un-wiring it.'],
            ['MARTIS_MCP_TRANSPORT', 'http', 'Default scaffold (v1.15.0+). Set to "stdio" for spawn-per-session legacy mode. See docs/agent-guidelines.md.'],
            ['MARTIS_MCP_HOST', '#127.0.0.1', null],
            ['MARTIS_MCP_PORT', '#8091', null],
            ['MARTIS_MCP_PATH', '#/mcp', null],
            ['MARTIS_MCP_URL', '#http://localhost:8091/mcp', null],
            ['MARTIS_MCP_HTTP_TOKEN', '#', null],
            ['MARTIS_MCP_HEALTH_PORT', '#', null],
        ];

        foreach (['.env', '.env.example'] as $file) {
            foreach ($entries as [$key, $value, $comment]) {
                // `#`-prefixed values are placeholders for commented-out lines.
                if (str_starts_with($value, '#')) {
                    $literalValue = substr($value, 1);
                    $env->setCommented($file, $key, $literalValue, $comment);
                } else {
                    $env->set($file, $key, $value, $comment);
                }
            }
        }
    }

    /**
     * @param  list<AgentProfile>  $profiles
     */
    private function runUnwire(array $profiles, McpConfigPatcher $mcp, EnvFilePatcher $env): int
    {
        foreach ($profiles as $profile) {
            if (! $profile->supportsMcp()) {
                continue;
            }
            if ($this->option('dry-run')) {
                $this->components->info('[dry-run] unwire MCP for '.$profile->label);

                continue;
            }
            $removed = $mcp->unwire($profile);
            $this->components->info($removed
                ? "Removed Martis MCP entry from {$profile->mcpConfigFile}"
                : "No Martis MCP entry found in {$profile->mcpConfigFile}; nothing to remove.");
        }

        if (! $this->option('dry-run')) {
            foreach (['.env', '.env.example'] as $file) {
                $env->remove($file, 'MARTIS_MCP_ENABLED');
            }
        }

        $this->components->info('MCP unwired.');

        return self::SUCCESS;
    }

    private function render(string $stub, bool $withMcp): string
    {
        $project = $this->guessProjectName();
        $namespace = $this->guessAppNamespace();
        $version = $this->guessMartisVersion();

        $rendered = strtr($stub, [
            '{{project_name}}' => $project,
            '{{namespace}}' => $namespace,
            '{{martis_version}}' => $version,
        ]);

        if ($withMcp) {
            $rendered = preg_replace('/{{MCP_SECTION}}\n?/', '', $rendered) ?? $rendered;
            $rendered = preg_replace('/{{\/MCP_SECTION}}\n?/', '', $rendered) ?? $rendered;
        } else {
            $rendered = preg_replace('/{{MCP_SECTION}}.*?{{\/MCP_SECTION}}\n?/s', '', $rendered) ?? $rendered;
        }

        return $rendered;
    }

    private function stubPath(): string
    {
        return __DIR__.'/../../stubs/agents/AGENTS.md.stub';
    }

    private function guessProjectName(): string
    {
        $composer = base_path().'/composer.json';
        if (file_exists($composer)) {
            $data = json_decode((string) file_get_contents($composer), associative: true);
            if (is_array($data) && isset($data['name']) && is_string($data['name'])) {
                return $data['name'];
            }
        }

        return Str::title(basename(base_path()));
    }

    private function guessAppNamespace(): string
    {
        $composer = base_path().'/composer.json';
        if (file_exists($composer)) {
            $data = json_decode((string) file_get_contents($composer), associative: true);
            $psr = $data['autoload']['psr-4'] ?? [];
            if (is_array($psr)) {
                foreach ($psr as $namespace => $path) {
                    if ($path === 'app/' || $path === 'app') {
                        return rtrim((string) $namespace, '\\');
                    }
                }
            }
        }

        return 'App';
    }

    private function guessMartisVersion(): string
    {
        $installed = base_path().'/vendor/composer/installed.json';
        if (file_exists($installed)) {
            $data = json_decode((string) file_get_contents($installed), associative: true);
            $packages = $data['packages'] ?? $data ?? [];
            foreach ($packages as $package) {
                if (($package['name'] ?? null) === 'martis/martis') {
                    return (string) ($package['version'] ?? 'unknown');
                }
            }
        }

        return 'unknown';
    }
}
