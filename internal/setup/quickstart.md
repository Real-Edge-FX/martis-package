# Quick Start

Get up and running with the Martis development environment.

## Prerequisites

- SSH access to the server: `ssh martis@192.168.50.21`
- Server is on the local network (no public internet)

## Starting the Development Server

```bash
# 1. Connect to the server
ssh martis@192.168.50.21

# 2. Enter the project directory
cd ~/martis

# 3. Verify you are on the develop branch (CRITICAL)
git branch  # Must show * develop

# 4. Start services (MySQL, Redis)
make start

# 5. Check that everything is running
make status

# 6. Open the admin panel
# http://martis.realedgefx.com/martis
# Login: admin@martis.local / password
```

## Development Workflow

### Feature Implementation

```bash
# 1. Create a feature branch from develop
git checkout develop
git checkout -b feature/REA-XXX-description

# 2. Implement changes in the appropriate directories:
#    Backend PHP:   packages/martis/src/
#    Frontend JS:   packages/martis/resources/js/
#    Playground:    playground/app/Martis/

# 3. Run CI before committing
make ci

# 4. Build assets if frontend changed
make build

# 5. Commit with conventional commit format
git add <files>
git commit -m "feat(scope): description

Co-Authored-By: Paperclip <noreply@paperclip.ing>"

# 6. Merge back to develop
git checkout develop
git merge feature/REA-XXX-description --no-edit

# 7. Delete the feature branch
git branch -d feature/REA-XXX-description

# 8. Push to GitHub
make push
```

### Important Rules

1. **Always use `make push`** — never `git push` directly. `make push` handles GitHub token refresh automatically.
2. **Always run `make build` before committing frontend changes** — the compiled assets in `public/` are part of the package release.
3. **Never leave the server on a feature branch** — always checkout back to `develop` before finishing.
4. **Always run `make ci`** before committing — the pre-push hook blocks pushes that fail CI.

### Frontend Hot Reload

For real-time frontend development:

```bash
# Terminal 1: Start Vite HMR
make assets-watch

# Terminal 2: Edit files in packages/martis/resources/js/
```

Note: `make assets-watch` is for development only. Before committing frontend changes or cutting a release, always run `make build`.

## Running Tests

```bash
make test           # All tests (Pest + Vitest)
make test-backend   # PHP tests only (Pest)
make test-frontend  # TypeScript tests only (Vitest)
make test-e2e       # E2E tests (Playwright)
make ci             # Full CI: lint + typecheck + test
make coverage       # PHP test coverage
```

## Useful Commands

```bash
make lint           # Run linters (Pint + ESLint)
make format         # Auto-fix formatting (Pint + ESLint --fix)
make typecheck      # PHPStan level 8 + tsc --noEmit
make fresh          # Reset database with fresh seeds
make deploy         # Manual deploy (pull + build + cache clear)
make status         # Check all services
make start          # Start Docker services
make stop           # Stop Docker services
```

## Adding a New Resource

Create a new PHP class in `playground/app/Martis/`:

```php
<?php

namespace App\Martis;

use App\Models\Product;
use Illuminate\Http\Request;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Fields\Number;
use Martis\Resource;

class ProductResource extends Resource
{
    public static function model(): string
    {
        return Product::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function icon(): string
    {
        return 'shopping-cart';
    }

    public function group(): ?string
    {
        return 'Products';
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            Text::make('name')->sortable()->searchable()->required(),
            Number::make('price')->min(0)->nullable(),
        ];
    }
}
```

The resource is auto-discovered — no manual registration needed.

## Creating Custom Components

Use the artisan command to scaffold custom React components:

```bash
php artisan martis:component StatusBadge --type=field
php artisan martis:component CustomLayout --type=layout
php artisan martis:component InfoPanel --type=generic
```

This creates the component file and registers it in `resources/js/martis/boot.ts`.

After creating components, rebuild:

```bash
make build
```

## Project Structure

```
/home/martis/martis/
├── packages/martis/              # The Martis package
│   ├── src/                      # PHP source (Resources, Fields, Controllers)
│   ├── resources/js/             # React + TypeScript frontend
│   ├── resources/css/            # CSS (Tailwind + PrimeReact)
│   ├── config/martis.php         # Default configuration
│   ├── routes/martis.php         # Route definitions
│   ├── stubs/                    # Code generation templates
│   └── tests/                    # Pest PHP + Vitest tests
├── playground/                   # Test Laravel application
│   ├── app/Models/               # Eloquent models
│   ├── app/Martis/               # Test resources
│   └── .env                      # Environment config
├── docs/                         # Documentation
├── docker/                       # Docker configs (MySQL, Redis)
├── Makefile                      # Build/CI/deploy scripts
└── docker-compose.yml            # Service definitions
```

## Next Steps

- [Resources](../resources.md) — Configure resource behavior
- [Fields Reference](../fields.md) — Explore all 32 field types
- [Override System](../overrides.md) — Customize components
- [Troubleshooting](troubleshooting.md) — Common issues
