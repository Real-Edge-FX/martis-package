# Troubleshooting

Common issues and solutions when working with the Martis development environment.

## PHP-FPM Not Starting

```bash
sudo systemctl status php8.2-fpm
sudo journalctl -u php8.2-fpm -n 50
```

**Common fix:** Check socket permissions at `/run/php/php8.2-fpm.sock`. The `listen.mode` should be `0666` in `/etc/php/8.2/fpm/pool.d/www.conf`.

## Caddy Returns 502 Bad Gateway

PHP-FPM is not running or the socket is inaccessible.

```bash
sudo systemctl restart php8.2-fpm
sudo systemctl restart caddy
```

## MySQL Container Crash Loop

Check logs and recreate the volume if corrupted:

```bash
sudo docker logs martis_mysql 2>&1 | tail -20
cd ~/martis && sudo docker compose down -v && sudo docker compose up -d mysql redis
```

## "Vite Manifest Not Found"

Frontend assets have not been built or published:

```bash
cd ~/martis
make build
```

If `make build` fails, try manually:

```bash
pnpm --filter @martis/martis build
cd playground && php8.2 artisan vendor:publish --tag=martis-assets --force
```

## Permission Denied on storage/

```bash
cd ~/martis
sudo chown -R martis:martis playground/storage playground/bootstrap/cache
chmod -R 775 playground/storage playground/bootstrap/cache
```

## Redis WRONGTYPE Error

Flush the Redis cache:

```bash
sudo docker exec martis_redis redis-cli FLUSHALL
```

## Frontend Changes Not Visible

This is the most common issue. After modifying files in `packages/martis/resources/js/`:

1. Run `make build` to compile new assets
2. Hard-refresh the browser (Ctrl+Shift+R)
3. Check that `packages/martis/public/manifest.json` has been updated

If using `make assets-watch` for development, ensure Vite HMR is running and connected.

## Git Push Fails with Authentication Error

Always use `make push` instead of `git push`. The `make push` command automatically refreshes the GitHub App token before pushing.

If `make push` also fails:

1. Check PEM key exists: `ls -la /home/martis/.github-app.pem`
2. Verify PyJWT is installed: `python3 -c "import jwt"`
3. If PEM is missing, escalate to the CEO agent

## Storage Link Missing (403 on Uploaded Files)

The symlink from `playground/public/storage` to `playground/storage/app/public` is required for serving uploaded files.

```bash
cd ~/martis/playground
php8.2 artisan storage:link
```

This is normally created automatically by `make build` and `make deploy`.

## CI Fails on PHPStan

PHPStan runs at level 8 (strict). Common issues:

- Missing return type declarations
- Unresolved generic types
- Contract/implementation method signature mismatch

```bash
# Run PHPStan alone to see detailed errors
cd ~/martis && make typecheck
```

## Server on Wrong Branch

The server must always be on the `develop` branch. If you find it on a feature branch:

```bash
cd ~/martis
git stash
git checkout develop
git stash pop  # only if you have uncommitted changes to keep
```

## Docker Services Not Starting

```bash
cd ~/martis
make stop
make start
make status
```

If containers are stuck:

```bash
sudo docker compose down
sudo docker compose up -d
```

## Database Migration Issues

```bash
cd ~/martis/playground
php8.2 artisan migrate:fresh --seed
```

Or use the Makefile shortcut:

```bash
make fresh
```
