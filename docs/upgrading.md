# Upgrading

What to do in an app when a 1.x release changes behaviour it may rely on. A patch release changes behaviour only to close a security gap or a bug; each section says what changed, who is affected and what to check.

## Upgrading to v1.39.3

v1.39.3 is a security release. After `composer update martis/martis`, in every environment:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear
```

`martis:cache:clear` matters on 1.x: the cache keys carry no package version and the `schema` layer keeps its entries with no expiration by default, so without it the cached resource schemas keep the relationship panel flags of the previous version (see [A write through a relationship needs the related `viewAny`](#a-write-through-a-relationship-needs-the-related-viewany)).

### Commands ask only on a terminal

`martis:install`, and every other Martis command that asks (the generators' "Overwrite?", `martis:user`, `martis:agents`, `martis:sso`'s role mapping, the "Run pending migrations now?" of `martis:invitations`, `martis:roles` and `martis:sso`), asks a question only when the input is interactive **and** stdin is a real TTY. Through a pipe or `docker compose exec -T` every command does what it does with `--no-interaction`:

- `martis:install` resolves every optional feature you pass no flag for to disabled, takes `profile_picture` as the avatar column unless you pass `--avatar-column`, and `--existing-avatar-column` needs `--avatar-column`;
- a generator leaves an existing file alone unless you pass `--force`, with the line and exit code it had (`martis:component` and `martis:theme` exit 1, `martis:card`, `martis:field` and `martis:tool` exit 0);
- `martis:user` needs `--email` and `--password` (it exits 1 naming the missing one and creates no user); `--name` defaults to `Martis Admin`;
- the scaffold commands run their migrations: `yes n | php artisan martis:invitations` used to answer "no" and skip them, so pass `--no-migrate` to skip them.

`martis:install --force` also leaves an application's own `*_create_notifications_table.php` / `*_create_sessions_table.php` (from `make:notifications-table` / `make:session-table`) alone: it rewrites only a migration whose header holds a sentence of the Martis stub.

**What to check:**

1. **A `users.y` column.** `yes | php artisan martis:install --with-profile` answered `y` to the avatar column question: look for a migration adding `y` to `users` in `database/migrations` and `MARTIS_AVATAR_COLUMN=y` in `.env`. Roll the migration back (or drop the column), delete it, set `MARTIS_AVATAR_COLUMN` to the column you want and run the installer again with `--with-profile --avatar-column=<column>` (and `--with-2fa` when you use two-factor authentication).
2. **A `martis:user` run through a pipe.** `yes | php artisan martis:user` created an admin with the email, name and password `y`, its email already verified: look for a user with the email `y` and delete it.
3. **Your own notifications or sessions migration.** If you ran `martis:install --force` over a migration you created with `make:notifications-table` or `make:session-table`, it holds the Martis stub: compare it with your version control and restore it.
4. **Scripts that relied on a prompt.** Pass the flags (`--with-profile`, `--with-2fa`, `--avatar-column=…`, `martis:user --email=… --password=…`, `--no-migrate`) instead.
