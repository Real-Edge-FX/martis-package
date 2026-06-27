# Design — Native HTTP (Streamable) transport for the Martis docs MCP

- **Status:** Approved — ready for implementation
- **Date:** 2026-06-27
- **Target release:** v1.13.0 (minor; additive, no breaking changes)
- **Source request:** RP-34/RP-35 (dev-knowledge-platform / martis-app), filed upstream as an enhancement against `martis/martis` v1.12.2.

## Context

The Martis docs MCP exists so coding agents (Claude Code, Cursor, Codex, Gemini) build with Martis without guessing — the three tools `martis_doc_list`, `martis_doc_read`, `martis_doc_search` serve the canonical docs at runtime. Today the server is **stdio-only** (`McpServeCommand::handle()` calls `$server->listen(new StdioServerTransport)`) and `martis:agents --with-mcp` writes a **spawn entry** into `.mcp.json` (`{command, args, cwd}` rooted at `base_path()`).

That coupling has bitten real consumers:

- The host MCP client must spawn a PHP subprocess with the right `cwd` and a working Laravel environment.
- Every client session re-spawns; there is no long-running shared instance to hot-connect.
- `.mcp.json` rooted in a sub-app is invisible to an agent rooted elsewhere — leading to "the docs MCP exists but is never reachable", and agents fall back to guessing.

The dependency already ships the fix: `php-mcp/server` v3.3 exposes `StreamableHttpServerTransport` next to `StdioServerTransport` and `HttpServerTransport`. Only the Martis command and the agents-wiring need to expose it. This design ships that exposure plus the production polish that makes the HTTP path operationally honest (health endpoint, optional bearer token, structured config namespace).

## Goals

1. Add HTTP (Streamable) transport as a first-class, opt-in deployment for `martis:mcp-serve` — long-running, networked, URL-addressable, hot-connectable.
2. Make `martis:agents --with-mcp` write a URL entry into `.mcp.json` when HTTP is configured, so MCP clients consume a stable URL with no subprocess juggling.
3. Promote the entire MCP config surface into the standard `config('martis.mcp.*')` namespace so it appears in `martis:list-env-vars` and survives `config:cache`.
4. Provide a real `/health` endpoint for container/k8s health checks (separate port, second ReactPHP socket on the same loop).
5. Support optional bearer-token auth on `/mcp` with a loud warning when the server binds publicly without a token.
6. Keep stdio as the default; preserve backward compatibility for every existing host.

## Non-goals

- TLS termination inside the PHP process. Operators front the HTTP listener with Caddy/nginx/Traefik for TLS.
- Per-client session state. The server is stateless (`stateless: true`, `enableJsonResponse: true`) — every request is self-contained, no SSE keepalive.
- Multi-tenant auth. Single shared token; multi-tenant authorization is out of scope.
- Tool-level rate limiting. Reverse proxies handle this if needed.

## Architecture

Single ReactPHP loop drives both sockets when transport is `http`:

```
┌──────────────────────────────────────────────────────────┐
│  martis:mcp-serve --transport=http                       │
│                                                          │
│  ┌──── ReactPHP loop (single) ────────────────────────┐  │
│  │                                                    │  │
│  │  ┌─ socket :8091 ──┐    ┌─ socket :8092 ────────┐  │  │
│  │  │ Authenticated   │    │ HealthServer          │  │  │
│  │  │ Streamable      │    │ (Martis-owned)        │  │  │
│  │  │ HttpServer      │    │                       │  │  │
│  │  │ Transport       │    │  GET /health -> 200   │  │  │
│  │  │  + bearer check │    │  JSON payload         │  │  │
│  │  │  /mcp           │    │                       │  │  │
│  │  └─────────────────┘    └───────────────────────┘  │  │
│  │                                                    │  │
│  │       SIGINT/SIGTERM → close both sockets          │  │
│  └────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────┘
```

The MCP transport is `Martis\Mcp\Transport\AuthenticatedStreamableHttpTransport`, a thin subclass of vendor's `StreamableHttpServerTransport` that overrides `createRequestHandler()` to front the bearer check. When the token env is unset/empty, the override is a pass-through. The `HealthServer` is a standalone `Martis\Mcp\Transport\HealthServer` class (~50 LOC) that wraps its own `React\Socket\SocketServer` + `React\Http\HttpServer` and is only constructed when `health_port > 0`.

stdio mode is untouched. `match($transport)` in `handle()` picks `StdioServerTransport` for `'stdio'` (default), or the authenticated HTTP transport + optional health server for `'http'`.

## Command surface

### `martis:mcp-serve` (modified)

New signature:

```
martis:mcp-serve
    {--transport= : stdio (default) or http. Overrides MARTIS_MCP_TRANSPORT}
    {--host= : HTTP bind host. Overrides MARTIS_MCP_HOST (default 127.0.0.1)}
    {--port= : HTTP port. Overrides MARTIS_MCP_PORT (default 8091)}
    {--path= : HTTP MCP endpoint path. Overrides MARTIS_MCP_PATH (default /mcp)}
    {--health-port= : Enable /health on this port. Overrides MARTIS_MCP_HEALTH_PORT (default 0 = off)}
    {--no-warn-on-public : Skip the "exposed without token" warning when host=0.0.0.0}
```

Flow:

1. Read `config('martis.mcp.enabled', true)`. If `false`, log info + continue (tools return `[]` per existing semantics).
2. Resolve transport precedence: CLI flag > env var > `'stdio'` default.
3. Resolve host/port/path/token/health_port: CLI flag > env > config default.
4. Build the server (`Server::make()->withTool(...)->build()` — unchanged).
5. `match ($transport)`:
   - `'stdio'` → `$server->listen(new StdioServerTransport)`.
   - `'http'` → construct `AuthenticatedStreamableHttpTransport(host, port, path, stateless: true, token: $tokenOrNull)`. If `health_port > 0`, instantiate `HealthServer($loop, $host, $health_port, $version, 'http')` and call `start()` before `$server->listen(...)`.
6. Boot warnings (single line each, stderr):
   - `host === '0.0.0.0' && token in ['', null] && !--no-warn-on-public` → loud warning that anyone reaching this port can call the docs API.
7. Signal handlers (`Loop::addSignal(SIGINT, ...)`, same for SIGTERM): close `HealthServer` (if running), close MCP transport, exit cleanly.
8. Existing `try/catch` wraps the whole flow; bind errors and the like surface as `FAILURE` with stderr explanation.

### `martis:agents --with-mcp` (modified)

`AgentsCommand::wireMcp()` (the spawn-entry writer today) branches on `config('martis.mcp.transport', 'stdio')`:

- `'http'` → write `{ "type": "http", "url": $url }` where `$url` is `config('martis.mcp.url')` or, when absent, a guess of `http://{host}:{port}{path}` substituting `0.0.0.0` → `localhost`.
- default → keep the current `{command, args, cwd}` spawn entry.

Validation: if `transport=http` and the URL is unresolvable (no env, no config, no flag), abort with a clear message instructing the operator to set `MARTIS_MCP_URL` or `MARTIS_MCP_HOST` + `MARTIS_MCP_PORT`.

The `.env` block written by `martis:agents --with-mcp` grows to include all seven MCP envs, with sensible defaults uncommented and HTTP-only knobs commented out:

```env
# Martis MCP server toggle (set to false to disable without un-wiring)
MARTIS_MCP_ENABLED=true
# stdio (default) or http
MARTIS_MCP_TRANSPORT=stdio
# HTTP transport — only used when MARTIS_MCP_TRANSPORT=http
# MARTIS_MCP_HOST=127.0.0.1
# MARTIS_MCP_PORT=8091
# MARTIS_MCP_PATH=/mcp
# MARTIS_MCP_URL=http://localhost:8091/mcp
# MARTIS_MCP_HTTP_TOKEN=
# MARTIS_MCP_HEALTH_PORT=
```

## Config namespace

New section in `config/martis.php` (flat, var_export-safe):

```php
/*
|--------------------------------------------------------------------------
| Martis MCP Server
|--------------------------------------------------------------------------
| Controls the docs MCP server (martis:mcp-serve) that exposes the
| Martis documentation to coding agents (Claude Code, Cursor, etc.).
| See docs/agent-guidelines.md for the full setup recipe.
*/
'mcp' => [
    'enabled'     => env('MARTIS_MCP_ENABLED', true),
    'transport'   => env('MARTIS_MCP_TRANSPORT', 'stdio'),
    'url'         => env('MARTIS_MCP_URL'),
    'host'        => env('MARTIS_MCP_HOST', '127.0.0.1'),
    'port'        => (int) env('MARTIS_MCP_PORT', 8091),
    'path'        => env('MARTIS_MCP_PATH', '/mcp'),
    'token'       => env('MARTIS_MCP_HTTP_TOKEN'),
    'health_port' => (int) env('MARTIS_MCP_HEALTH_PORT', 0),
],
```

Side effect: `martis:list-env-vars` (which introspects `config/martis.php`) auto-lists the eight new env vars with no extra code.

## Components (new)

### `Martis\Mcp\Transport\AuthenticatedStreamableHttpTransport`

Thin subclass of vendor `StreamableHttpServerTransport`. Constructor accepts the existing args + an optional `?string $token`. Overrides `createRequestHandler()` to wrap the parent handler in a bearer-check closure that 401s on mismatch. When `$token` is `null` or empty, the override is a transparent pass-through. ~25 LOC.

Risk: vendor's `createRequestHandler` is `private`. The subclass relies on the method existing and being overridable. Mitigation:
- Composer constraint pinned at `^3.3` (already in place).
- Defensive `VendorContractTest` that uses reflection to assert `createRequestHandler` is present and reachable. Fails CI on vendor breaks before integration tests do.
- Spec note: upstream PR to add a `withMiddleware()` hook is desirable but out of scope; if accepted upstream, we drop the subclass.

### `Martis\Mcp\Transport\HealthServer`

Standalone class. Constructs its own `SocketServer` + `HttpServer` on the shared loop. Handles `GET /health` → 200 JSON `{status, version, transport, uptime_s, tool_count}`. Any other method/path → 404. ~50 LOC.

Payload shape:

```json
{
  "status": "ok",          // "ok" when Tools::enabled() else "disabled"
  "version": "1.13.0",     // from composer.json at boot
  "transport": "http",
  "uptime_s": 1234,
  "tool_count": 3          // 3 normally, 0 when disabled
}
```

## Backward compatibility

- Default transport stays `stdio`. Hosts on v1.12.x see no behavioural change after upgrading; the `.mcp.json` entry the package wrote yesterday still works.
- `MARTIS_MCP_ENABLED` continues to honour the same semantics; the only change is it is now read via `config()` first with `getenv()` as a fallback so cold-bootstrap paths still work.
- `Tools::enabled()` keeps its current contract — `(bool) (getenv('MARTIS_MCP_ENABLED') !== 'false' && config('martis.mcp.enabled', true) !== false)` — to cover both standalone-PHP and Laravel-bootstrapped invocations.

## Auth posture

- Default: token absent, host = `127.0.0.1` → loopback only, no auth needed.
- Token set → `/mcp` requires `Authorization: Bearer <token>`. Mismatch returns 401 JSON `{"error":"unauthorized"}`.
- `host === '0.0.0.0' && token in ['', null]` → warning at boot (stderr). Operator opts in to public exposure consciously.
- `--no-warn-on-public` suppresses the warning for shops with their own reverse-proxy auth.

## Operations recipes (docs)

New subsection "Running the MCP over HTTP" in `docs/agent-guidelines.md` (mirror in `martis-docs/src/content/customization/agent-guidelines.mdx`):

- When to use HTTP vs stdio (table).
- Zero-to-running .env recipe + the two artisan calls.
- docker-compose snippet with healthcheck hitting `:8092/health`.
- systemd unit snippet with `Restart=on-failure`.
- MCP client config samples (Claude Code, Cursor, Codex, Gemini — each their own `.mcp.json` / `.cursor/mcp.json` / `~/.codex/config.toml` / `.gemini/settings.json` shape with `type: http + url`).
- Troubleshooting: 401 explained, 0.0.0.0 warning, port collision, `MARTIS_MCP_ENABLED=false` semantics.

## Test plan

Pest, target ~40 new tests. Existing 2009-test suite must stay green.

### `tests/Feature/McpServeCommandTransportTest.php`

End-to-end via `Symfony\Component\Process`:

- stdio handshake + `tools/list` returns the three tools (regression guard for the default path).
- http: spawn `martis:mcp-serve --transport=http --port=<rand>`, wait for socket ready, `POST /mcp` with `tools/list`, assert 200 + the three tools.
- http + token: same flow with `MARTIS_MCP_HTTP_TOKEN=<rand>`; missing header → 401, correct header → 200.
- http + health: with `--health-port=<rand>`, `GET /health` returns 200 JSON with expected fields.
- `MARTIS_MCP_ENABLED=false`: in both transports, handshake succeeds but `tools/list` returns `[]`.
- `host=0.0.0.0` without token: stderr contains the warning; `--no-warn-on-public` suppresses.
- Signal handling: send SIGTERM, process exits within 2s with code 0.

### `tests/Feature/Console/AgentsCommandHttpTransportTest.php`

Reflection + in-process pattern (same shape as the v1.12.0 AgentsCommandTest):

- `MARTIS_MCP_TRANSPORT=http` + explicit `MARTIS_MCP_URL` → `.mcp.json` gets `{type: http, url: …}` (no `command`/`args`/`cwd`).
- `MARTIS_MCP_TRANSPORT=http` + URL absent → guesses `http://{host}:{port}{path}`; substitutes `0.0.0.0` → `localhost`.
- `MARTIS_MCP_TRANSPORT=http` + URL absent + host absent → aborts with a clear message.
- `MARTIS_MCP_TRANSPORT=stdio` (default) → current spawn entry preserved.
- `MARTIS_MCP_ENABLED=false` → wiring is skipped with a clear message.

### `tests/Feature/Mcp/HealthServerTest.php`

Unit:

- `GET /health` returns 200 with the expected JSON shape.
- `GET /` (or anything but `/health`) returns 404.
- `POST /health` returns 404 (we collapse "wrong method" into "no such route" for one branch only; simpler than 405 and the health probe never POSTs).
- `Tools::enabled() === false` → payload `status: disabled`, `tool_count: 0`.

### `tests/Feature/Mcp/VendorContractTest.php`

Reflection-based defensive guard:

- `StreamableHttpServerTransport::createRequestHandler` exists.
- The method is overridable (visible from a subclass — either `protected` or `private` with the same signature we depend on).
- Constructor signature matches the args our subclass passes through.

On a vendor major bump, this test fails CI before anything else does, with a clear message naming the broken contract.

### Manual smoke

- `martis-playground`: `make up`, bring the docker stack online, point a fresh `.mcp.json` at `http://localhost:8091/mcp`, run `php artisan martis:mcp-serve --transport=http`, hit `/mcp` and `/health` with curl, verify stdio still works after toggling env back.
- Reflection on session-end memory: confirm CHANGELOG, agent-guidelines, and martis-docs landing pill all reflect v1.13.0.

## Rollout

1. Branch `feat/mcp-http-transport-v1.13.0` off `main`.
2. Implement components in order: config namespace → `HealthServer` → `AuthenticatedStreamableHttpTransport` + `VendorContractTest` → `McpServeCommand` refactor → `AgentsCommand` branch + `.env` block update → docs.
3. Triple check (pest + phpstan + pint).
4. Manual smoke in `martis-playground`.
5. PR to `Real-Edge-FX/martis-package` (matching the `martis-issues` enhancement protocol).
6. Mirror docs PR in `Real-Edge-FX/martis-docs` (new subsection in installation-guide / agent-guidelines + landing pill bump to v1.13.0).
7. Wait CI green (4 lanes — L12 + L13 × PHP 8.3 + 8.4 after the L11 drop in v1.12.1).
8. `pre-tag-check.sh v1.13.0`.
9. Merge, tag `v1.13.0`, publish GitHub release with notes.
10. Verify martis-docs deploy serves the new section + landing pill.
11. (Defer) Open upstream issue on `php-mcp/server` requesting a `withMiddleware()` hook so we can drop the subclass in a future patch.

## Acceptance criteria

- `php artisan martis:mcp-serve --transport=http --port=8091` serves the three doc tools over Streamable HTTP at `http://<host>:8091/mcp`; an MCP client configured with `{"type":"http","url":...}` lists/reads/searches docs with no subprocess spawn.
- `martis:agents --with-mcp` with `MARTIS_MCP_TRANSPORT=http` writes the URL entry; with the stdio default writes the existing command entry.
- `MARTIS_MCP_ENABLED=false` keeps the handshake intact but exposes zero tools, in both transports.
- `MARTIS_MCP_HTTP_TOKEN` set → 401 without bearer; correct bearer → 200.
- `MARTIS_MCP_HEALTH_PORT=8092` → `GET /health` returns the documented JSON; default 0 → port closed.
- `martis:list-env-vars` lists all eight new envs without manual additions.
- Existing stdio setups keep working unchanged. Full Pest suite green at ≥ 2049 passing.

## Open questions / risks

- **`createRequestHandler` access modifier in `php-mcp/server` upstream**. Mitigation lives in `VendorContractTest`; if upstream tightens visibility we ship a patch reverting to the "no token, doc-recommended reverse proxy" path.
- **Docker healthcheck dependency on `wget`**. The snippet uses `wget`; some thin PHP images don't ship it. Fallback documented (use `php -r "fopen('http://localhost:8092/health','r');"` or `curl`).
- **SIGPIPE / orphaned-fd on shared loop shutdown**. ReactPHP signal handling on PHP CLI is robust on Linux/macOS; not tested on Windows (Martis doesn't target Windows anyway).
