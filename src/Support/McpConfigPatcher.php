<?php

declare(strict_types=1);

namespace Martis\Support;

/**
 * Idempotent merge of the Martis MCP server entry into an agent's
 * config file. Supports JSON (Claude Code, Cursor, Gemini) and TOML
 * (Codex). Other servers the user has configured are preserved
 * untouched; only the entry under `mcpServers.martis` (or the
 * agent-specific equivalent root key) is overwritten.
 *
 * Writing always creates parent directories. Reading a malformed
 * config returns an empty document so the patch is non-destructive
 * even on a corrupted starting state, but a `.bak` snapshot of the
 * original file is dropped next to it before any rewrite.
 */
class McpConfigPatcher
{
    public const ENTRY_KEY = 'martis';

    public function __construct(private readonly string $basePath) {}

    /**
     * @param  array<string, mixed>  $entry  e.g. ['command' => 'php', 'args' => [...], 'cwd' => '/abs/path']
     */
    public function patch(AgentProfile $profile, array $entry): string
    {
        $this->ensureWritable($profile);

        $absolute = $this->resolve($profile);
        $existing = $this->readConfig($absolute, $profile->mcpFormat);
        if (file_exists($absolute)) {
            $this->writeBackup($absolute);
        }

        $servers = is_array($existing[$profile->mcpRootKey] ?? null)
            ? $existing[$profile->mcpRootKey]
            : [];
        $servers[self::ENTRY_KEY] = $entry;
        $existing[$profile->mcpRootKey] = $servers;

        $this->writeConfig($absolute, $existing, $profile->mcpFormat);

        return $absolute;
    }

    public function unwire(AgentProfile $profile): bool
    {
        if (! $profile->supportsMcp()) {
            return false;
        }

        $absolute = $this->resolve($profile);
        if (! file_exists($absolute)) {
            return false;
        }

        $existing = $this->readConfig($absolute, $profile->mcpFormat);
        $servers = is_array($existing[$profile->mcpRootKey] ?? null)
            ? $existing[$profile->mcpRootKey]
            : [];

        if (! array_key_exists(self::ENTRY_KEY, $servers)) {
            return false;
        }

        unset($servers[self::ENTRY_KEY]);
        if ($servers === []) {
            unset($existing[$profile->mcpRootKey]);
        } else {
            $existing[$profile->mcpRootKey] = $servers;
        }

        $this->writeBackup($absolute);
        $this->writeConfig($absolute, $existing, $profile->mcpFormat);

        return true;
    }

    public function resolve(AgentProfile $profile): string
    {
        if (! $profile->supportsMcp()) {
            throw new \LogicException("Agent {$profile->key} does not support MCP wiring.");
        }

        return $this->basePath.'/'.$profile->mcpConfigFile;
    }

    private function ensureWritable(AgentProfile $profile): void
    {
        $absolute = $this->resolve($profile);
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(string $absolute, ?string $format): array
    {
        if (! file_exists($absolute)) {
            return [];
        }

        $contents = (string) file_get_contents($absolute);
        if (trim($contents) === '') {
            return [];
        }

        return match ($format) {
            AgentProfile::FORMAT_JSON => $this->safeJsonDecode($contents),
            AgentProfile::FORMAT_TOML => $this->safeTomlDecode($contents),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeConfig(string $absolute, array $payload, ?string $format): void
    {
        $body = match ($format) {
            AgentProfile::FORMAT_JSON => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            AgentProfile::FORMAT_TOML => $this->encodeToml($payload),
            default => '',
        };

        file_put_contents($absolute, $body);
    }

    private function writeBackup(string $absolute): void
    {
        $backup = $absolute.'.bak';
        if (file_exists($backup)) {
            $backup = $absolute.'.bak.'.date('YmdHis');
        }
        copy($absolute, $backup);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeJsonDecode(string $contents): array
    {
        try {
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeTomlDecode(string $contents): array
    {
        $current = '';
        $out = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $m)) {
                $current = $m[1];

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.+)$/', $trimmed, $m)) {
                $value = $this->parseTomlValue($m[2]);
                $this->arraySetByPath($out, $current === '' ? $m[1] : $current.'.'.$m[1], $value);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeToml(array $payload): string
    {
        $lines = [];
        $this->encodeTomlLevel($payload, '', $lines);

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $lines
     */
    private function encodeTomlLevel(array $payload, string $prefix, array &$lines): void
    {
        $scalars = [];
        $tables = [];
        foreach ($payload as $k => $v) {
            if (is_array($v) && $this->isAssoc($v)) {
                $tables[$k] = $v;
            } else {
                $scalars[$k] = $v;
            }
        }
        if ($scalars !== [] && $prefix !== '') {
            $lines[] = '['.$prefix.']';
        }
        foreach ($scalars as $k => $v) {
            $lines[] = $k.' = '.$this->tomlEncodeValue($v);
        }
        foreach ($tables as $k => $v) {
            $sub = $prefix === '' ? $k : $prefix.'.'.$k;
            $lines[] = '';
            $this->encodeTomlLevel($v, $sub, $lines);
        }
    }

    /**
     * @param  array<int|string, mixed>  $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function tomlEncodeValue(mixed $value): string
    {
        if (is_string($value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $items = array_map(fn ($v) => $this->tomlEncodeValue($v), array_values($value));

            return '['.implode(', ', $items).']';
        }

        return '""';
    }

    /**
     * @return string|int|float|bool|list<mixed>
     */
    private function parseTomlValue(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        if (preg_match('/^"(.*)"$/', $raw, $m)) {
            return stripcslashes($m[1]);
        }
        if (preg_match('/^\[(.*)\]$/', $raw, $m)) {
            $items = array_map('trim', explode(',', $m[1]));

            return array_values(array_filter(array_map(
                fn (string $i) => $i === '' ? null : $this->parseTomlValue($i),
                $items,
            ), fn ($v) => $v !== null));
        }
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $arr
     */
    private function arraySetByPath(array &$arr, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor = &$arr;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }
}
