# Project Context

## What Martis is

Martis is a package under active development.

This repository, `martis-package`, is the main codebase where package features, internal logic, frontend assets, configuration, installation flow, and publishable resources are developed and maintained.

## Repository role

Use this repository to:

- implement new package features
- update backend logic
- update frontend assets
- change package configuration
- update publishing and installation behavior
- evolve the package architecture

This repository is the source of truth for package behavior.

## Workspace structure

Expected local workspace:

```bash
martis/
├── martis-package
└── martis-playground
```

## Relationship with the Playground

`martis-playground` is the Laravel application used to validate this package inside a real environment.

The package is consumed there through a local Composer path repository:

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

This means package code developed here is linked directly into the Playground when the workspace structure is correct.

## Development guidance

### Backend and PHP

Package backend logic belongs here.

Use the Playground to validate real integration behavior after making changes.

### Config

If package config changes and the config is publishable, republish config in the Playground:

```bash
cd ../martis-playground
make command CMD="artisan vendor:publish --tag=martis-config --force"
```

### Frontend assets

If package assets change:

```bash
npm run build
cd ../martis-playground
make command CMD="artisan vendor:publish --tag=martis-assets --force"
```

### Full install flow

If installation behavior changes, rerun the installer in the Playground:

```bash
cd ../martis-playground
make command CMD="artisan martis:install --force --with-profile"
```

## Important rules

- Do not implement package logic in the Playground unless the change is explicitly playground-only.
- Avoid hardcoded local machine paths.
- Keep Composer path assumptions compatible with the workspace structure.
- Prefer minimal and safe changes.
- Use the Playground as the validation environment, not as the primary implementation location.
