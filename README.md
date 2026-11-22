![Packagist Version](https://img.shields.io/packagist/v/dynamia-tools/buckie)
![Packagist Downloads](https://img.shields.io/packagist/dt/dynamia-tools/buckie)
![PHP Version](https://img.shields.io/packagist/php-v/dynamia-tools/buckie)
![License](https://img.shields.io/packagist/l/dynamia-tools/buckie)

# Buckie PHP

PHP 8.2+ implementation of Buckie — a filesystem-native file server with a minimal S3-style REST API.

Designed for **shared hosting and standard PHP environments**: no framework, no database, no message broker. Just PHP and a disk.

> **Standalone application** — Buckie PHP runs as a self-contained CLI tool and/or HTTP server. It is **not** a reusable library to be required inside another project.

---

## Usage modes

| Mode | How | Description |
|---|---|---|
| **CLI** | `php bin/buckie <command>` | Manage buckets, identities, files and permissions from the terminal |
| **HTTP server (dev)** | `php bin/buckie serve` | Starts PHP's built-in server — useful for local development |
| **HTTP server (prod)** | Apache / Nginx / FrankenPHP | Deploy `public/index.php` under any standard PHP web server |

---

## Features

- **Zero external dependencies** — only PHP 8.2+ and standard extensions (GD, finfo)
- **Single config file** — all state lives in `.buckie/config.json`
- **On-the-fly thumbnails** — GD (default) or Imagick; cached outside the buckets, with strict parameter limits
- **Same REST API as the Node.js implementation**
- **Same CLI contract as the Node.js implementation**
- **Local FS only** — no SFTP; ideal for shared hosting
- **Authentication** via `X-Buckie-Identity`/`X-Buckie-Secret` headers or HTTP Basic Auth
- **Secret hashing** with `password_hash(PASSWORD_DEFAULT)` (bcrypt) — cross-compatible with the Node.js implementation
- **Staging + atomic rename** for uploads — no partial reads

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | **8.2** |
| Composer | 2+ |
| GD extension | any recent version (or Imagick) |
| finfo extension | bundled with PHP 8 |
| JSON extension | bundled with PHP 8 |

---

## Installation

### Option A — create-project (recommended)

```bash
composer create-project dynamia-tools/buckie buckie-php
cd buckie-php
```

This installs Buckie as a standalone application in the `buckie-php/` directory.

### Option B — clone + install

```bash
git clone https://github.com/dynamia-tools/buckie buckie-php
cd buckie-php/buckie-php
composer install
```

### Make the CLI available globally

```bash
# Link the binary to a directory in your $PATH
ln -s "$(pwd)/bin/buckie" ~/.local/bin/buckie
chmod +x ~/.local/bin/buckie
```

---

## Quick Start

### As a CLI tool

All management operations (buckets, identities, permissions, files) are handled through the `buckie` CLI:

```bash
# Create a bucket
php bin/buckie create bucket documents /var/storage/documents

# Create an identity (the secret is read from STDIN or BUCKIE_IDENTITY_SECRET,
# so it never shows up in `ps` or your shell history)
printf '%s' 'my-secret-password' | php bin/buckie create identity erp-prod

# Grant permissions
php bin/buckie grant erp-prod documents --read --write --delete --prefix /tenant-a/

# List all buckets
php bin/buckie list buckets

# Upload a file directly from the terminal
php bin/buckie upload documents tenant-a/invoice.pdf ./invoice.pdf
```

Run `php bin/buckie --help` to see all available commands.

### As an HTTP server (development)

```bash
php bin/buckie serve --host 0.0.0.0 --port 8080
```

### As an HTTP server (production)

Deploy the `public/` directory under Apache, Nginx or FrankenPHP (see [Production Deployment](#production-deployment) below).

---

## Production Deployment

Buckie PHP is deployed like any standard PHP application. Set the document root to the `public/` directory.

### Apache

Make sure `mod_rewrite` is enabled. The `public/.htaccess` file already contains the required configuration.

```apache
<VirtualHost *:80>
    ServerName storage.example.com
    DocumentRoot /var/www/buckie/public

    <Directory /var/www/buckie/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Never serve dotfiles
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
</VirtualHost>
```

### Nginx

```nginx
server {
    listen 80;
    server_name storage.example.com;
    root /var/www/buckie/public;
    index index.php;

    # Never serve dotfiles (.buckie, .git, ...)
    location ~ /\. {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Only index.php is executable; any other .php path is routed to Buckie like any other key
    location = /index.php {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### FrankenPHP

```bash
frankenphp php-server --root public/
```

### Docker (local testing with Apache)

The repository ships a `Dockerfile` and a `docker-compose.yml` for local testing.
Inside the container use the `buckie` wrapper: it runs the CLI as `www-data`, the user Apache runs as,
so the private (`0600`) config file stays readable by the web server.

```bash
# 1. Build the image and start the container
docker compose up --build -d

# 2. Check that the service is up
curl http://localhost:8080/health

# 3. Create a bucket (the path must exist INSIDE the container)
docker exec buckie-php buckie create bucket docs /var/buckie-storage/docs

# 4. Create an identity (the secret is read from STDIN)
printf '%s' 'secret123' | docker exec -i buckie-php buckie create identity dev-user

# 5. Grant permissions
docker exec buckie-php buckie grant dev-user docs --read --write --delete

# 6. Upload a test file
curl -X PUT http://localhost:8080/docs/hello.txt \
  -H "X-Buckie-Identity: dev-user" \
  -H "X-Buckie-Secret: secret123" \
  -H "Content-Type: text/plain" \
  --data-binary "Hello from Buckie PHP"

# 7. Download it
curl http://localhost:8080/docs/hello.txt \
  -H "X-Buckie-Identity: dev-user" \
  -H "X-Buckie-Secret: secret123"

# 8. Stop the container
docker compose down
```

Data persists between restarts in `./docker-data/`:

```
docker-data/
├── buckie/          ← config.json, logs/, cache/
└── storage/         ← uploaded files
```

**Environment variables** (configurable in `docker-compose.yml`):

| Variable | Docker default | Description |
|---|---|---|
| `BUCKIE_DATA_DIR` | `/var/buckie-data` | Config, logs and cache |
| `BUCKIE_STORAGE_DIR` | `/var/buckie-storage` | File storage |
| `BUCKIE_LOG_LEVEL` | `debug` | Log level |

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `BUCKIE_DATA_DIR` | `<project root>/.buckie` | Data directory (config, logs, cache). Set it explicitly in production and keep it **outside the web root** |
| `BUCKIE_HOST` | `127.0.0.1` | Bind host (development server only) |
| `BUCKIE_PORT` | `8080` | Bind port (development server only) |
| `BUCKIE_LOG_LEVEL` | `info` | Log level |
| `BUCKIE_MAX_UPLOAD_BYTES` | `536870912` (512 MiB) | Maximum upload size; `0` disables the limit. Over-limit uploads get `413` |
| `BUCKIE_TRUST_PROXY` | _(unset)_ | Set to `1` to log the client IP from `X-Forwarded-For`. Only enable it behind a proxy you control |
| `BUCKIE_IDENTITY_SECRET` | _(unset)_ | Secret used by `buckie create identity` when none is passed as an argument |

---

## CLI Reference

```bash
# Development server (not for production)
php bin/buckie serve [--host <host>] [--port <port>] [--data-dir <dir>]

# Buckets
php bin/buckie create bucket <name> <absolutePath>
php bin/buckie list buckets
php bin/buckie remove bucket <name>

# Identities
php bin/buckie create identity <identity> [<secret>]   # secret: argument, BUCKIE_IDENTITY_SECRET or STDIN
php bin/buckie list identities
php bin/buckie remove identity <identity>

# Permissions
php bin/buckie grant <identity> <bucket> --read --write --delete [--prefix <path>]
php bin/buckie revoke <identity> <bucket>

# Files
php bin/buckie list files <bucket> [path]
php bin/buckie upload <bucket> <key> <localFile>
php bin/buckie copy <srcBucket> <srcKey> <dstBucket> <dstKey>

# Provisioning (create identity + secret + grant in one step)
php bin/buckie provision <bucket> [--identity <name>] [--prefix <path>] [--read] [--write] [--delete]

# Shell tab completion
eval "$(php bin/buckie completion bash)"   # Bash  — add to ~/.bashrc
eval "$(php bin/buckie completion zsh)"    # Zsh   — add to ~/.zshrc
php bin/buckie completion fish > ~/.config/fish/completions/buckie.fish
```

---

## REST API

All requests (except `GET /health`) require authentication via `X-Buckie-Identity` + `X-Buckie-Secret` headers, or HTTP Basic Auth.

### Health check
```http
GET /health
```

### Download a file
```http
GET /:bucket/:key
X-Buckie-Identity: erp-prod
X-Buckie-Secret: my-secret-password
```

### Download a thumbnail (on-the-fly resize)
```http
GET /:bucket/:key?w=300&h=300&fit=cover&format=webp
```

Supported query parameters: `w` (width), `h` (height), `fit` (`cover` | `contain` | `fill`), `format` (`webp` | `jpeg` | `png`; `jpg` is accepted as an alias).

`w` and `h` must be integers between `0` and `4096`. Any invalid value is rejected with `400`
(`BUCKIE_THUMBNAIL_PARAM_INVALID`). Thumbnails are cached in `<data dir>/cache/thumbnails/`, never inside the bucket.

### List a directory
```http
GET /:bucket/:path/
```

### List bucket contents (paginated)
```http
GET /:bucket?limit=100&cursor=...
```

### Upload a file
```http
PUT /:bucket/:key
Content-Type: application/octet-stream
[body: file stream]
```

### Delete a file
```http
DELETE /:bucket/:key
```

---

## curl Examples

```bash
# Health check
curl http://localhost:8080/health

# Upload a file
curl -X PUT http://localhost:8080/documents/tenant-a/invoice.pdf \
  -H "X-Buckie-Identity: erp-prod" \
  -H "X-Buckie-Secret: my-secret-password" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @invoice.pdf

# Upload using HTTP Basic Auth
curl -X PUT http://localhost:8080/documents/tenant-a/report.xlsx \
  -u "erp-prod:my-secret-password" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @report.xlsx

# Download a file
curl http://localhost:8080/documents/tenant-a/invoice.pdf \
  -H "X-Buckie-Identity: erp-prod" \
  -H "X-Buckie-Secret: my-secret-password" \
  -o invoice.pdf

# Download a thumbnail
curl "http://localhost:8080/documents/tenant-a/photo.jpg?w=300&h=300&fit=cover&format=webp" \
  -H "X-Buckie-Identity: erp-prod" \
  -H "X-Buckie-Secret: my-secret-password" \
  -o thumbnail.webp

# List a directory
curl http://localhost:8080/documents/tenant-a/ \
  -H "X-Buckie-Identity: erp-prod" \
  -H "X-Buckie-Secret: my-secret-password"

# List bucket contents (paginated)
curl "http://localhost:8080/documents?limit=50" \
  -H "X-Buckie-Identity: erp-prod" \
  -H "X-Buckie-Secret: my-secret-password"

# Delete a file
curl -X DELETE http://localhost:8080/documents/tenant-a/invoice.pdf \
  -H "X-Buckie-Identity: erp-prod" \
  -H "X-Buckie-Secret: my-secret-password"
```

---

## Project Structure

```
.
├── bin/
│   └── buckie              ← CLI entry point (executable)
├── public/
│   ├── index.php           ← HTTP entry point (framework-free router)
│   └── .htaccess           ← Apache mod_rewrite configuration
├── src/
│   ├── Auth/
│   │   └── IdentityService.php      ← Identity CRUD, hashing, permission check
│   ├── Config/
│   │   └── ConfigService.php        ← config.json read/write, data directory paths
│   ├── Errors/
│   │   └── BuckieException.php      ← Typed exceptions with HTTP status
│   ├── Http/
│   │   └── Router.php               ← HTTP routing and request handling
│   ├── Logging/
│   │   └── OperationalLogger.php    ← JSONL access and error logs
│   ├── Storage/
│   │   ├── BucketService.php        ← Bucket CRUD, path traversal protection
│   │   └── LocalStorageService.php  ← Upload/download/delete/list operations
│   └── Thumbnail/
│       └── ThumbnailService.php     ← On-the-fly thumbnails via GD/Imagick
└── composer.json
```

---

## Data Directory Structure

```
.buckie/
 ├── config.json    # Buckets, identities and grants
 ├── logs/
 │   ├── access.log.jsonl
 │   └── error.log.jsonl
 └── cache/
      └── thumbnails/  # Generated thumbnails (hashed file names)
```

Each local bucket also maintains an internal directory:

```
/path/to/bucket/
 ├── tenant-a/
 │    └── invoice.pdf
 └── .buckie/
      └── staging/          ← Temporary upload staging area
```

---

## Security notes

- **Data directory**: `config.json` holds identity hashes and bucket paths. Keep `BUCKIE_DATA_DIR` outside the web root.
  Buckie creates it with mode `0700` and `config.json` with `0600`, and refuses to overwrite a config it cannot parse.
- **Grant prefixes** respect path-segment boundaries: `--prefix /tenant-a` covers `/tenant-a` and `/tenant-a/...`,
  never `/tenant-ab/...`. Prefer a trailing slash (`/tenant-a/`) for clarity.
- **Symlinks** that point outside a bucket are never followed, for reads, writes or deletes.
- **Downloads** are sent with `X-Content-Type-Options: nosniff` and a sandboxing `Content-Security-Policy`;
  HTML, SVG and script content is forced to `Content-Disposition: attachment`.
- **No rate limiting** is built in. Put Buckie behind a reverse proxy (or WAF) that limits login attempts.
- **Imagick**: if you use it instead of GD, install a restrictive ImageMagick `policy.xml`. Buckie only hands it
  JPEG, PNG, GIF, WebP and BMP files, within memory and time limits.
- The built-in server (`buckie serve`) is single-threaded, binds to `127.0.0.1` by default and is for development only.

---

## Differences from the Node.js Implementation

| Feature | Node.js | PHP |
|---|---|---|
| Storage backends | Local FS + SFTP | **Local FS only** |
| Secret hashing | bcrypt (`bcrypt` npm) | `password_hash` / bcrypt |
| Hash interoperability | ✅ Compatible | ✅ Compatible |
| HTTP server | Fastify | PHP built-in / Apache / Nginx / FrankenPHP |
| File streaming | Node.js streams | `fpassthru` |
| Thumbnails | Sharp | GD / Imagick |
| Programmatic API | Full SDK | Not available |

---

## Security

- **Private by default** — every request requires authentication; only `/health` is public
- **bcrypt password hashing** — secrets are never stored in plain text; hashes are cross-compatible with the Node.js implementation
- **Prefix-based authorization** — access can be restricted by bucket and path prefix
- **Path traversal protection** — canonical path validation on every request
- **Staging + atomic commit** — uploads are written to staging first to prevent partial reads

---

## Related Implementations

| Implementation | Repository | Best for |
|---|---|---|
| **Buckie Node.js** | [github.com/dynamiatools/buckie-node-js](https://github.com/dynamiatools/buckie-node-js) | High-throughput servers — includes SFTP storage backend and full programmatic TypeScript SDK |

---

## License

MIT © Dynamia Soluciones IT SAS
