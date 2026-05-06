# Agent guidelines and the Martis MCP server

Martis ships a generator for AI coding agents (Claude Code, Codex, Cursor, Gemini, Copilot) plus an optional MCP server that exposes the package documentation over the Model Context Protocol.

The goal is to make agent-assisted development on Martis productive out of the box: one command produces a dense, prescriptive primer your agent loads on session start, and an optional MCP server lets the agent fetch deep dives on demand without scanning your `vendor/` tree.

## TL;DR

```bash
# Generate guidelines for whichever agent you have configured locally,
# wire the MCP server, set the env toggle.
php artisan martis:agents
```

The command is interactive by default. Pass flags for non-interactive runs (CI, scripts).

## What the command does

1. Detects which agents your project uses (looks for `.claude/`, `.cursor/`, `.gemini/`, `.codex/`, etc.).
2. Asks you to confirm the selection (or pick from the full list when no signal is present).
3. Writes a primer file per selected agent. The same byte-identical `AGENTS.md` is written under the agent-specific filename (`CLAUDE.md`, `.cursorrules`, `GEMINI.md`, `.github/copilot-instructions.md`).
4. Asks whether to wire the Martis MCP server into the agent's MCP config file.
5. Writes the MCP entry idempotently and adds `MARTIS_MCP_ENABLED=true` to your `.env` and `.env.example`.

The primer covers Martis idioms: the 31 generators, field rules, resource conventions, soft gates, the var_export-safe plan resolver, the i18n contract, and concentrated anti-patterns. It points the agent at `vendor/martis/martis/docs/` for deep dives, with a slug table.

## Flags

| Flag | Purpose |
|---|---|
| `--agent=claude,cursor` | Bypass detection. Comma-separated. |
| `--with-mcp` | Skip the MCP question, wire it. |
| `--without-mcp` | Skip the MCP question, do not wire. |
| `--mcp-only` | Only patch the MCP config + `.env`. Do not touch guideline files. |
| `--mcp-unwire` | Remove the Martis entry from the agent's MCP config and `MARTIS_MCP_ENABLED` from `.env`. |
| `--force` | Overwrite existing guideline files without prompting. |
| `--dry-run` | Print the planned actions, write nothing. |
| `--no-interaction` | Disable prompts (combine with `--agent` and `--with-mcp` / `--without-mcp` for CI). |

## Lifecycle scenarios

| Scenario | Command |
|---|---|
| First run, full setup with MCP | `php artisan martis:agents --with-mcp` |
| First run, no MCP | `php artisan martis:agents --without-mcp` |
| Already ran without MCP, want to add it now | `php artisan martis:agents --mcp-only` |
| Disable MCP temporarily | edit `.env` → `MARTIS_MCP_ENABLED=false` |
| Remove MCP entirely | `php artisan martis:agents --mcp-unwire` |
| Re-generate guidelines after a package upgrade | `php artisan martis:agents --force` |
| Add support for a second agent later | `php artisan martis:agents --agent=cursor` (additive) |

## The MCP server

`php artisan martis:mcp-serve` is a stdio MCP server. Wire it into your agent's MCP config and the agent gets three tools:

- `martis_doc_list` — every Martis doc with a one-line description.
- `martis_doc_read` — full markdown of one doc by slug.
- `martis_doc_search` — top matches for a query, with snippets.

The server reads `MARTIS_MCP_ENABLED` at boot. When `false`, the tools return a short notice instead of running. This lets you toggle the integration on and off via `.env` without editing your agent's MCP config.

### Manual MCP wiring

If you prefer to wire MCP yourself, add this to your agent's MCP config (`.mcp.json`, `.cursor/mcp.json`, etc.):

```json
{
  "mcpServers": {
    "martis": {
      "command": "php",
      "args": ["artisan", "martis:mcp-serve"],
      "cwd": "/absolute/path/to/your/laravel/app"
    }
  }
}
```

Codex uses a TOML format under `[mcp_servers.martis]` instead of `mcpServers.martis`. The `martis:agents --mcp-only` flow does this for you.

## Detection table

| Agent | Detection signals | Guideline file | MCP config file |
|---|---|---|---|
| Claude Code | `.claude/`, `CLAUDE.md`, `.mcp.json` | `CLAUDE.md` | `.mcp.json` |
| Cursor | `.cursor/`, `.cursorrules` | `.cursorrules` | `.cursor/mcp.json` |
| Gemini CLI | `.gemini/`, `GEMINI.md` | `GEMINI.md` | `.gemini/settings.json` |
| Codex | `.codex/`, `codex.toml` | `AGENTS.md` | `.codex/config.toml` |
| GitHub Copilot | `.github/copilot-instructions.md`, `.copilot/` | `.github/copilot-instructions.md` | (no MCP wiring in MVP) |

`AGENTS.md` is always written: most agents read it as a fallback primer.

## Customising the primer

The primer is rendered from a stub at `stubs/agents/AGENTS.md.stub` inside the package. Three placeholders are substituted at write time:

- `{{project_name}}` — pulled from your `composer.json` `name`, falls back to the directory basename.
- `{{namespace}}` — resolved from the `App\` PSR-4 mapping in `composer.json`.
- `{{martis_version}}` — read from `vendor/composer/installed.json`.

The MCP section is wrapped in `{{MCP_SECTION}}...{{/MCP_SECTION}}` markers and rendered only when MCP is wired. To override the stub for your project, publish it through the existing `martis:stubs` mechanism and edit your local copy.

## Idempotency

Every write the command performs is idempotent. Re-running with the same flags produces a zero diff:

- Guideline files are byte-comparable across runs (same stub, same substitutions).
- The MCP config patcher merges the `martis` entry without disturbing other servers you may have configured. A `.bak` of the config file is dropped beside it before any rewrite.
- The `.env` patcher only adds `MARTIS_MCP_ENABLED` when missing — it never overwrites an operator-set value.
