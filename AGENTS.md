# AGENTS.md

## Read first

Before making any changes, read:

- `docs/PROJECT_CONTEXT.md`

## Repository purpose

This repository is `martis-package`, the source of truth for the `martis/martis` package.

Implement package logic here unless a task is explicitly playground-specific.

## Working rules

- Treat this repository as the primary location for package code.
- Implement backend, frontend, config, install flow, and publishable resource changes here.
- Do not move package logic into `martis-playground`.
- Keep changes minimal, safe, and reversible.
- Avoid hardcoded machine-specific paths.
- Assume this package is tested through the sibling `martis-playground` repository.
- When a change needs real-app validation, use the Playground instead of mocking the integration here.

## Relationship with the Playground

Expected workspace structure:

```bash
martis/
├── martis-package
└── martis-playground
```

The Playground consumes this package locally through Composer using:

```json
"repositories": [
    {
        "type": "path",
        "url": "../martis-package",
        "options": {
            "symlink": true
        }
    }
]
```

## Validation workflow

After changing package code:

- validate backend behavior in the Playground
- if config changed, republish config in the Playground
- if assets changed, rebuild assets here and republish them in the Playground
- if install flow changed, rerun the installer in the Playground

## Useful validation commands

Republish config in the Playground:

```bash
cd ../martis-playground
make command CMD="artisan vendor:publish --tag=martis-config --force"
```

Rebuild assets in this package and republish them in the Playground:

```bash
npm run build

cd ../martis-playground
make command CMD="artisan vendor:publish --tag=martis-assets --force"
```

Run the full install flow in the Playground:

```bash
cd ../martis-playground
make command CMD="artisan martis:install --force --with-profile"
```
