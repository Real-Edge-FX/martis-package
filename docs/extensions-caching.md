# Extensions: caching

*Since v2.20.0.* The extension bundle (`npm run build:extensions`) is a static file under `public/vendor/martis-user/`. Serving it with the right headers means a returning operator loads it from the browser cache instead of the network, and a deploy still reaches everyone on the next visit.

## What Martis does

- **The entry URL carries a version.** The shell emits `/vendor/martis-user/extensions.js?v=<12 hex>`, a hash of the file's content, for every root-relative `MARTIS_EXTENSIONS` URL that is a file under `public/`. The URL changes when, and only when, the file does. Absolute URLs, URLs that already have a query string, and files that do not exist are emitted as configured. Turn it off with `MARTIS_EXTENSIONS_VERSIONED_URLS=false` (`martis.extensions_versioned_urls`).
- **Chunks have hashed names.** A tool or card registered with `componentRegistry.registerLazy` (see [Overrides](overrides.md#lazy-registration-v2200)) is built into `chunks/<name>-<hash>.js` next to the entry. The hash is in the file name, so a chunk's URL never serves different content.
- **Stale tabs recover.** A tab opened before a deploy still points at the old chunk names. When one is gone, Martis reloads the tab once and shows an error state if that does not help (`MARTIS_EXTENSIONS_RELOAD_ON_STALE=false` disables the reload). Keep the previous release's chunks on disk for a short while after a deploy if you want such tabs to keep working without a reload.

## What to send

| Request | Header |
|---------|--------|
| The entry with `?v=` (`/vendor/martis-user/extensions.js?v=...`) | `Cache-Control: public, max-age=31536000, immutable` |
| Hashed chunks (`/vendor/martis-user/chunks/*`) | `Cache-Control: public, max-age=31536000, immutable` |
| The bare entry (no `?v=`) and anything else under `/vendor/martis-user/` | `Cache-Control: no-cache` |

`immutable` is safe for the first two rows because their URLs change with their content. `no-cache` does not forbid caching: the browser stores the file and revalidates it (`ETag` / `Last-Modified`) on each use, so an unversioned URL never serves an old bundle. The `?v=` rule only applies while `martis.extensions_versioned_urls` is on: with it off, send `no-cache` for the entry too.

The snippets below assume the files are served from `public/` at `/vendor/martis-user/`. Adjust the path if you changed `build.outDir`.

### Caddy

```caddyfile
example.com {
    root * /var/www/app/public

    @entryVersioned {
        path /vendor/martis-user/extensions.js
        query v=*
    }
    @chunks path /vendor/martis-user/chunks/*
    @folder path /vendor/martis-user/*

    # The two specific rules set the header; the catch-all (`?`) only sets
    # it where no earlier rule did, so it is the default for the folder.
    header @entryVersioned Cache-Control "public, max-age=31536000, immutable"
    header @chunks Cache-Control "public, max-age=31536000, immutable"
    header @folder ?Cache-Control "no-cache"

    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

The `?` prefix of `?Cache-Control` sets the header only when none was set before it, which makes the third rule a default. Keep it after the other two.

### nginx

```nginx
server {
    root /var/www/app/public;

    # Hashed chunks: the hash is in the file name.
    location ^~ /vendor/martis-user/chunks/ {
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        try_files $uri =404;
    }

    # The entry: immutable with ?v=, revalidated without it.
    location = /vendor/martis-user/extensions.js {
        set $martis_cache "no-cache";
        if ($arg_v != "") {
            set $martis_cache "public, max-age=31536000, immutable";
        }
        add_header Cache-Control $martis_cache always;
        try_files $uri =404;
    }

    # Everything else in the folder.
    location ^~ /vendor/martis-user/ {
        add_header Cache-Control "no-cache" always;
        try_files $uri =404;
    }
}
```

`add_header` in a `location` replaces the headers of the enclosing `server`, so repeat any you need (security headers, for instance) in each block.

### Apache

```apache
<IfModule mod_headers.c>
    # Default for the folder: revalidate.
    <LocationMatch "^/vendor/martis-user/">
        Header set Cache-Control "no-cache"
    </LocationMatch>

    # Hashed chunks.
    <LocationMatch "^/vendor/martis-user/chunks/">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </LocationMatch>

    # The entry, only when it is requested with ?v=.
    SetEnvIf Request_URI "^/vendor/martis-user/extensions\.js$" MARTIS_ENTRY
    SetEnvIf Query_String "(^|&)v=" MARTIS_VERSIONED
    <If "%{ENV:MARTIS_ENTRY} == '1' && %{ENV:MARTIS_VERSIONED} == '1'">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </If>
</IfModule>
```

If the app sits behind a CDN, make sure it honours the origin's `Cache-Control` and keeps the query string in its cache key; a CDN that drops `?v=` serves one entry to every version.

## Checking it

```bash
curl -sI 'https://example.com/vendor/martis-user/extensions.js?v=abc' | grep -i cache-control
curl -sI 'https://example.com/vendor/martis-user/extensions.js'       | grep -i cache-control
curl -sI 'https://example.com/vendor/martis-user/chunks/Reports-3f9c1a2b.js' | grep -i cache-control
```

The first and third answer `public, max-age=31536000, immutable`; the second answers `no-cache`. In the browser, the Network panel shows `(disk cache)` for the first two on a second visit.
