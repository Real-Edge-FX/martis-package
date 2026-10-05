# Agent guidelines and the Martis MCP server

Martis ships a guidelines generator for five AI coding agents: Claude Code, Codex, Cursor, Gemini and Copilot. An optional MCP server exposes the package documentation over the Model Context Protocol.

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
| Re-generate guidelines after a package upgrade | `php artisan martis:agents --force` (a primer template you published keeps your edits; see [Customising the primer](#customising-the-primer)) |
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

With the MCP wired the primer does not repeat what the MCP serves: it names
`martis_doc_list()` for the page index instead of listing the slugs, states the
MCP-only rule once, and leaves the transport and the runtime knobs to this page
(they are the operator's concern). The file-based primer keeps the slug table
with the file paths. Before v1.38.1 the MCP-wired primer also carried the slug
table, the MCP-only rule four times and the operator-only subsections, about
15 KB against about 10 KB now.

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

Martis ships a docs MCP server built on [`laravel/mcp`](https://github.com/laravel/mcp). Wire it into your agent's MCP config and the agent gets three read-only tools:

- `martis_doc_list`: every Martis doc with a one-line description.
- `martis_doc_read`: the full markdown of one doc by slug.
- `martis_doc_search`: the top matches for a query, with snippets.

`MARTIS_MCP_ENABLED` is read on every tool call. When it is `false` the server still lists the three tools, and each one answers with a short notice (`enabled: false`) instead of running. This toggles the integration from `.env` without editing your agent's MCP config.

The server speaks two transports, chosen with `MARTIS_MCP_TRANSPORT`:

| | stdio (default) | HTTP |
|---|---|---|
| How the agent connects | spawns `php artisan mcp:start martis-docs` | calls a route of your app |
| Needs | the PHP CLI | the app served at `APP_URL`, and a token outside `local` |
| Right for | local development, one agent per session | a shared endpoint for several agents or machines |

### stdio (default)

```bash
php artisan martis:agents --with-mcp
```

writes this entry (Claude Code `.mcp.json`; Cursor, Gemini and Codex get the same server in their own file):

```json
{ "mcpServers": { "martis": { "command": "php", "args": ["artisan", "mcp:start", "martis-docs"], "cwd": "/absolute/path/to/your/app" } } }
```

Each agent session spawns one process, which boots Laravel once and exits when the agent closes it.

### HTTP

```bash
# .env
MARTIS_MCP_TRANSPORT=http
MARTIS_MCP_HTTP_TOKEN=   # required outside the local environment: openssl rand -hex 32

php artisan martis:agents --with-mcp
```

With `MARTIS_MCP_TRANSPORT=http` Martis registers a `POST` route on your app at `/{MARTIS_PATH}/mcp` (`/martis/mcp` by default; `MARTIS_MCP_PATH` changes it). The route sits outside the `web` middleware group: no session, no CSRF. `GET` and `DELETE` on the same URL answer 405: only `POST` is the MCP endpoint. `martis:agents` writes a URL entry built from `APP_URL` and the path:

```json
{ "mcpServers": { "martis": { "type": "http", "url": "https://your-app.test/martis/mcp" } } }
```

Set `MARTIS_MCP_URL` when the agent reaches the app at another address than `APP_URL` (for example `http://localhost:8000/martis/mcp` from the host when the app runs in Docker).

### Authentication

- With `MARTIS_MCP_HTTP_TOKEN` set, a request passes only with `Authorization: Bearer <token>` (compared in constant time); anything else gets `401 {"error":"unauthorized"}` with a `WWW-Authenticate: Bearer` challenge.
- Without a token the route serves only the `local` and `testing` environments. In any other environment it answers 401 with a message naming `MARTIS_MCP_HTTP_TOKEN`, so a deployed app never exposes the MCP unauthenticated.

`martis:agents` never writes the token into the agent's config, which is usually committed. Add the header by hand where your agent keeps secrets, for example in Claude Code:

```json
{ "mcpServers": { "martis": { "type": "http", "url": "https://your-app.test/martis/mcp", "headers": { "Authorization": "Bearer ${MARTIS_MCP_HTTP_TOKEN}" } } } }
```

Liveness is your app's own health route (`/up` in a default Laravel app): the MCP has no separate `/health` endpoint.

### Troubleshooting

- **`Command "martis:mcp-serve" is not defined`**: the agent config predates v2.5.0. Run `php artisan martis:agents --with-mcp` again.
- **401 with a message about `MARTIS_MCP_HTTP_TOKEN`**: the app is not in the `local` environment and has no token. Set one.
- **401 `{"error":"unauthorized"}`**: the client sends no `Authorization: Bearer <token>` header, or a different token.
- **404 on the MCP URL**: `MARTIS_MCP_TRANSPORT` is not `http` in the app's environment, or the routes were cached before you set it (`php artisan route:clear`).
- **Every tool answers `enabled: false`**: `MARTIS_MCP_ENABLED=false`.
- **Boot fails with `MARTIS_MCP_TRANSPORT must be "stdio" or "http"`**: fix the value; leave it unset for stdio.
- **`martis:agents` refuses to run and names `MARTIS_MCP_HOST`, `MARTIS_MCP_PORT` or `MARTIS_MCP_HEALTH_PORT`**: those settings belonged to the standalone daemon removed in v2.5.0. Delete them. The command exits with status 1 before writing anything, also with `--dry-run`; `--mcp-unwire` is not blocked.

### Manual MCP wiring

To wire the MCP yourself, add the stdio entry above (or the URL entry for HTTP) to your agent's MCP config (`.mcp.json`, `.cursor/mcp.json`, `.gemini/settings.json`). Codex uses TOML under `[mcp_servers.martis]` instead of `mcpServers.martis`. `martis:agents --mcp-only` does this for you.

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

The primer is rendered from the `agents/AGENTS.md.stub` template, resolved like every generator stub: your project's `stubs/martis/agents/AGENTS.md.stub` when it exists, the package's otherwise. Publish it with the other stubs and edit your copy:

```bash
php artisan martis:stubs            # writes stubs/martis/agents/AGENTS.md.stub among the others
php artisan martis:agents --force   # renders your edited copy
```

A published template keeps your edits through `martis:agents --force`, the post-upgrade step above; compare it with the package's after an upgrade (`php artisan martis:stubs --force` into a scratch checkout, or a diff against `vendor/martis/martis/stubs/agents/AGENTS.md.stub`) to pick up new guidance. With no published template the output is the package's, byte for byte.

Two placeholders are substituted at write time:

- `{{project_name}}` — pulled from your `composer.json` `name`, falls back to the directory basename.
- `{{namespace}}` — resolved from the `app/` PSR-4 mapping in `composer.json`.

The primer names no package version: it is a committed file, and a version stamped when it was generated goes stale on the next upgrade, so it points the agent at `composer.lock`. Two kinds of conditional block work in a published copy too: `{{MCP_SECTION}}…{{/MCP_SECTION}}` is kept only when the MCP is wired, `{{^MCP_SECTION}}…{{/^MCP_SECTION}}` only when it is not.

Before v1.38.1 `martis:stubs` did not publish the primer template and the command never read a project copy, so `--force` discarded every edit, and the primer stamped the installed version (`{{martis_version}}`), which went stale on every upgrade.

## Idempotency

Every write the command performs is idempotent. Re-running with the same flags produces a zero diff:

- Guideline files are byte-comparable across runs (same stub, same substitutions).
- The MCP config patcher merges the `martis` entry without disturbing other servers you may have configured. A `.bak` of the config file is dropped beside it before any rewrite.
- The `.env` patcher only adds `MARTIS_MCP_ENABLED` when missing — it never overwrites an operator-set value.
