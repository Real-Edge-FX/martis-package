<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * Identifies one supported coding agent and the paths Martis writes to
 * when wiring its guidelines + MCP server for that agent.
 *
 * @see AgentDetector
 * @see McpConfigPatcher
 */
final class AgentProfile
{
    public const FORMAT_JSON = 'json';

    public const FORMAT_TOML = 'toml';

    /**
     * @param  list<string>  $detectionPaths  files/dirs (relative to base path) whose presence flags this agent
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $guidelineFile,
        public readonly array $detectionPaths,
        public readonly ?string $mcpConfigFile,
        public readonly ?string $mcpFormat,
        public readonly string $mcpRootKey = 'mcpServers',
    ) {}

    public function supportsMcp(): bool
    {
        return $this->mcpConfigFile !== null && $this->mcpFormat !== null;
    }
}
