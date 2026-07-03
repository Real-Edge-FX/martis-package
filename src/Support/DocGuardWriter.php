<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * Installs the Claude Code "doc guard": a PreToolUse hook that blocks
 * filesystem reads of the Martis docs so agents can only read them through
 * the docs MCP. Writes `.claude/martis-doc-guard.php` (the guard script,
 * shipped as a stub) and merges a PreToolUse entry into
 * `.claude/settings.json`. Idempotent: a second run is a no-op.
 *
 * Claude Code specific — other agents (Cursor/Gemini/Codex/Copilot) use
 * different hook mechanisms and are not covered here.
 */
class DocGuardWriter
{
    private const HOOK_MARKER = 'martis-doc-guard.php';

    private const HOOK_COMMAND = 'php "$CLAUDE_PROJECT_DIR/.claude/martis-doc-guard.php"';

    public function __construct(private readonly string $base) {}

    /**
     * @return array{script: string, settings: string, action: 'patched'|'unchanged'}
     */
    public function install(): array
    {
        $claudeDir = $this->base.'/.claude';
        if (! is_dir($claudeDir)) {
            mkdir($claudeDir, 0755, true);
        }

        $scriptPath = $claudeDir.'/martis-doc-guard.php';
        file_put_contents($scriptPath, $this->guardScript());

        $settingsPath = $claudeDir.'/settings.json';
        $settings = [];
        if (is_file($settingsPath)) {
            $decoded = json_decode((string) file_get_contents($settingsPath), true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $changed = $this->ensureHook($settings);
        if ($changed) {
            file_put_contents(
                $settingsPath,
                json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );
        }

        return [
            'script' => $scriptPath,
            'settings' => $settingsPath,
            'action' => $changed ? 'patched' : 'unchanged',
        ];
    }

    /**
     * Merge our PreToolUse hook into $settings. Returns true if it changed.
     *
     * @param  array<string, mixed>  $settings
     */
    private function ensureHook(array &$settings): bool
    {
        $hooks = is_array($settings['hooks'] ?? null) ? $settings['hooks'] : [];
        $pre = is_array($hooks['PreToolUse'] ?? null) ? $hooks['PreToolUse'] : [];

        foreach ($pre as $entry) {
            $inner = is_array($entry) && is_array($entry['hooks'] ?? null) ? $entry['hooks'] : [];
            foreach ($inner as $h) {
                if (is_array($h) && str_contains((string) ($h['command'] ?? ''), self::HOOK_MARKER)) {
                    return false; // already installed
                }
            }
        }

        $pre[] = [
            'matcher' => 'Bash|Read|Grep|Glob',
            'hooks' => [[
                'type' => 'command',
                'command' => self::HOOK_COMMAND,
            ]],
        ];

        $hooks['PreToolUse'] = $pre;
        $settings['hooks'] = $hooks;

        return true;
    }

    private function guardScript(): string
    {
        return (string) file_get_contents(__DIR__.'/../../stubs/agents/martis-doc-guard.php.stub');
    }
}
