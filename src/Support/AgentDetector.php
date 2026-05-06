<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * Detects which coding agents are in use in a host Laravel project.
 *
 * Detection is based on conventional config paths each agent ships
 * with. The detector is intentionally additive: a project that uses
 * both Claude Code and Cursor surfaces both agents, the user picks.
 *
 * @see AgentProfile
 */
class AgentDetector
{
    public function __construct(private readonly string $basePath) {}

    /**
     * @return list<AgentProfile>
     */
    public function profiles(): array
    {
        return [
            new AgentProfile(
                key: 'claude',
                label: 'Claude Code',
                guidelineFile: 'CLAUDE.md',
                detectionPaths: ['.claude', 'CLAUDE.md', '.mcp.json'],
                mcpConfigFile: '.mcp.json',
                mcpFormat: AgentProfile::FORMAT_JSON,
            ),
            new AgentProfile(
                key: 'cursor',
                label: 'Cursor',
                guidelineFile: '.cursorrules',
                detectionPaths: ['.cursor', '.cursorrules'],
                mcpConfigFile: '.cursor/mcp.json',
                mcpFormat: AgentProfile::FORMAT_JSON,
            ),
            new AgentProfile(
                key: 'gemini',
                label: 'Gemini CLI',
                guidelineFile: 'GEMINI.md',
                detectionPaths: ['.gemini', 'GEMINI.md'],
                mcpConfigFile: '.gemini/settings.json',
                mcpFormat: AgentProfile::FORMAT_JSON,
            ),
            new AgentProfile(
                key: 'codex',
                label: 'Codex',
                guidelineFile: 'AGENTS.md',
                detectionPaths: ['.codex', 'codex.toml'],
                mcpConfigFile: '.codex/config.toml',
                mcpFormat: AgentProfile::FORMAT_TOML,
                mcpRootKey: 'mcp_servers',
            ),
            new AgentProfile(
                key: 'copilot',
                label: 'GitHub Copilot',
                guidelineFile: '.github/copilot-instructions.md',
                detectionPaths: ['.github/copilot-instructions.md', '.copilot'],
                mcpConfigFile: null,
                mcpFormat: null,
            ),
        ];
    }

    /**
     * Return only agents whose detection paths exist in the host project.
     *
     * @return list<AgentProfile>
     */
    public function detect(): array
    {
        $hits = [];
        foreach ($this->profiles() as $profile) {
            foreach ($profile->detectionPaths as $relative) {
                if (file_exists($this->basePath.'/'.$relative)) {
                    $hits[] = $profile;

                    break;
                }
            }
        }

        return $hits;
    }

    public function profileByKey(string $key): ?AgentProfile
    {
        foreach ($this->profiles() as $profile) {
            if ($profile->key === $key) {
                return $profile;
            }
        }

        return null;
    }
}
