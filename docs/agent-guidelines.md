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
| `--with-doc-guard` | (Claude Code) install a PreToolUse hook that blocks filesystem reads of the Martis docs, forcing use of the docs MCP. See "Docs are read through the MCP" below. |
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
| Enforce MCP-only doc reads (Claude Code) | `php artisan martis:agents --with-mcp --with-doc-guard` |

## Docs are read through the MCP

When the MCP is wired, the generated `AGENTS.md` / `CLAUDE.md` route **every**
documentation lookup through the MCP tools (`martis_doc_search`,
`martis_doc_read`, `martis_doc_list`) and never point the agent at the raw
`docs/*.md` files in `vendor/`. This is deliberate: the MCP returns scoped,
ranked, token-cheap results and is the single source of truth. If a tool call
reports `enabled: false` (or errors), the guidance is to **stop and ask the
operator to re-enable/restart the MCP**, not to fall back to the files. When no
MCP is wired, the guidelines list the file paths, because then the files are the
only source.

### Optional: machine-enforced doc guard (`--with-doc-guard`)

Prose relies on the model complying. For a fool-proof guarantee on **Claude
Code**, `--with-doc-guard` installs:

- `.claude/martis-doc-guard.php` — a small guard script, and
- a `PreToolUse` hook in `.claude/settings.json` (matcher `Bash|Read|Grep|Glob`)
  that runs it.

The guard blocks any tool call that **reads** a Martis doc from the filesystem
(`Read`/`Grep`/`Glob` on the docs dir, or a Bash command that opens a concrete
`docs/<slug>.md` file) and steers the agent to the MCP. It inspects the specific
tool-input fields, so a command that merely *mentions* the docs directory as a
search string (e.g. `grep 'vendor/martis/martis/docs' somefile`) is **not**
blocked. Re-running the command is idempotent, and it preserves any hooks you
already have. Pair it with `--with-mcp` so the agent has the MCP to fall back to.
The guard is Claude-Code specific; other agents use different hook mechanisms.

## The MCP server

`php artisan martis:mcp-serve` is a stdio MCP server. Wire it into your agent's MCP config and the agent gets three tools:

- `martis_doc_list` — every Martis doc with a one-line description.
- `martis_doc_read` — full markdown of one doc by slug.
- `martis_doc_search` — top matches for a query, with snippets.

The server reads `MARTIS_MCP_ENABLED` at boot. When `false`, the tools return a short notice instead of running. This lets you toggle the integration on and off via `.env` without editing your agent's MCP config.

## Running the MCP over HTTP (default since v1.15.0)

`martis:mcp-serve` ships two transports.

- **HTTP (default for new installs since v1.15.0)** — a long-running server bound to `127.0.0.1:8091/mcp`. The MCP client connects by URL. Right when the MCP server is a shared service — multiple agents, multiple sessions, containers, or any environment where spawning PHP from the agent client is awkward. `martis:agents --with-mcp` now writes the URL entry into `.mcp.json` and `MARTIS_MCP_TRANSPORT=http` into `.env` by default.
- **stdio (legacy / opt-in fallback)** — the MCP client spawns a PHP subprocess on demand and tears it down at session end. Right for single-agent dev loops with zero infrastructure. To opt in, set `MARTIS_MCP_TRANSPORT=stdio` in `.env` before running `martis:agents --with-mcp`. Existing v1.12.x / v1.13.x / v1.14.x consumers whose `.mcp.json` already carries the stdio spawn entry keep working unchanged.

### When to use which

| | HTTP (default) | stdio (legacy) |
|---|---|---|
| Local dev, single agent | ✓ long-running, hot-reconnect | ✓ zero infra |
| Multiple agents / sessions | ✓ long-running, shared | spawn-per-session, slow |
| Container deploy | ✓ ships PHP once, exposes URL | needs PHP in client image |
| Hot-reload after package upgrade | ✓ restart server, agents reconnect | client must restart |
| Network exposure | ✓ via reverse proxy | n/a |

### Zero-to-running (HTTP)

```bash
# .env
MARTIS_MCP_TRANSPORT=http
MARTIS_MCP_HOST=0.0.0.0
MARTIS_MCP_PORT=8091
MARTIS_MCP_HEALTH_PORT=8092
MARTIS_MCP_HTTP_TOKEN=  # optional bearer token (recommended when host=0.0.0.0)

# 1. Wire each agent's .mcp.json with the URL entry
php artisan martis:agents --with-mcp

# 2. Run the server long-lived (foreground for dev; systemd / compose for prod)
php artisan martis:mcp-serve
```

The `martis:agents` command writes a URL entry into `.mcp.json` like:

```json
{ "mcpServers": { "martis": { "type": "http", "url": "http://localhost:8091/mcp" } } }
```

`MARTIS_MCP_URL` overrides the auto-built URL. Without it, the value is built from host+port+path with `0.0.0.0` → `localhost`.

### docker-compose

```yaml
services:
  martis-mcp:
    image: php:8.4-cli
    working_dir: /app
    volumes: ["./:/app"]
    command: ["php", "artisan", "martis:mcp-serve"]
    environment:
      MARTIS_MCP_TRANSPORT: http
      MARTIS_MCP_HOST: 0.0.0.0
      MARTIS_MCP_PORT: 8091
      MARTIS_MCP_HEALTH_PORT: 8092
      MARTIS_MCP_HTTP_TOKEN: ${MARTIS_MCP_HTTP_TOKEN}
    ports: ["8091:8091"]
    healthcheck:
      test: ["CMD", "wget", "-q", "-O-", "http://localhost:8092/health"]
      interval: 30s
      timeout: 5s
      retries: 3
    restart: unless-stopped
```

### systemd

```ini
# /etc/systemd/system/martis-mcp.service
[Unit]
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/your-app
EnvironmentFile=/var/www/your-app/.env
ExecStart=/usr/bin/php artisan martis:mcp-serve
Restart=on-failure
RestartSec=3s

[Install]
WantedBy=multi-user.target
```

### Auth posture

The docs are public (anyone can `composer require martis/martis` and read them). When `MARTIS_MCP_HTTP_TOKEN` is unset, the HTTP endpoint accepts any caller. Set the token whenever you bind to a non-loopback host:

```bash
MARTIS_MCP_HTTP_TOKEN=$(openssl rand -hex 32)
```

When `host=0.0.0.0` without a token, `martis:mcp-serve` prints a warning at boot. Suppress with `--no-warn-on-public` if you front the server with an authenticated reverse proxy.

### `/health` endpoint

Opt-in: set `MARTIS_MCP_HEALTH_PORT=8092` (or pass `--health-port=8092`). The endpoint lives at `GET /health` and returns:

```json
{
  "status": "ok",
  "version": "1.13.0",
  "transport": "http",
  "uptime_s": 1234,
  "tool_count": 3
}
```

`status` becomes `"disabled"` and `tool_count` becomes `0` when `MARTIS_MCP_ENABLED=false`.

### Troubleshooting

- **401 unauthorized**: token mismatch. Check that the client sends `Authorization: Bearer <token>` and the value matches `MARTIS_MCP_HTTP_TOKEN`.
- **"could not bind to port"**: another process holds the port. Pick a different one with `--port=...`.
- **`MARTIS_MCP_ENABLED=false`**: handshake still works (clients see the server) but `tools/list` returns `[]`. Useful for emergency disabling without un-wiring.
- **Logs**: go to stderr in both transports. systemd captures via `journalctl -u martis-mcp`; compose captures via `docker compose logs -f martis-mcp`.

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
