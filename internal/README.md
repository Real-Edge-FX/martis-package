# Internal Documentation

This folder holds operational and architecture documentation that **must not** ship to consumers.

These files were previously under `docs/` but contain server-private examples (the playground host name, default admin credentials, an internal IP, the path to the SSH key). The public docs site at <https://martis-docs.realedgefx.com> mirrors `docs/`; anything in this folder is deliberately excluded from that mirror.

| File | Why it lives here |
|------|-------------------|
| `architecture/decisions.md` | Mentions internal IP and proxy topology |
| `architecture/stack.md` | Mentions the production server IP |
| `release-process.md` | Contains an SSH command with the internal IP and the path to the deploy key |
| `setup/quickstart.md` | Dev environment for the team — SSH access, default credentials, infra URLs |
| `setup/troubleshooting.md` | Dev environment troubleshooting — same scope as quickstart |

## Rules

1. **Never reference these files from `docs/`.** A reference creates a broken link on the public site and leaks the file's existence.
2. **Never copy snippets from these files into `docs/` without sanitising.** Default credentials, internal hostnames, and IPs must be replaced with `admin@example.com`, `your-app.example.com`, `<server-ip>`.
3. **Sync guard**: the `martis-docs` repo's `scripts/sync-docs.mjs` carries a regex deny-list (`admin@martis.local`, `192.168.50.21`, `martis.realedgefx.com`, `secrets/martis*ed25519`, `martis-docs.realedgefx.com`) that fails the docs-site build if any of those patterns survive a sync. That is the safety net; this folder is the deliberate quarantine.

## Related

- Real-Edge-FX/martis-package#94 — issue that motivated this split
- Real-Edge-FX/martis-package#95 — `api/overview.md` will be sanitised when the OpenAPI surface ships, and the docs site will then sync it directly
