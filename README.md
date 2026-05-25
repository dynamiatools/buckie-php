# Buckie PHP

PHP 8+ implementation of Buckie — a filesystem-native file server with a minimal S3-style REST API.

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

- **Zero external dependencies** — only PHP 8.0+ and standard extensions (GD, finfo)
- **Single config file** — all state lives in `.buckie/config.json`
- **On-the-fly thumbnails** — GD (default) or Imagick; automatic caching
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
| PHP | **8.0** |
| Composer | 2+ |
| GD extension | any recent version (or Imagick) |
| finfo extension | bundled with PHP 8 |

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

# Create an identity
php bin/buckie create identity erp-prod my-secret-password

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
</VirtualHost>
```

### Nginx

```nginx
server {
    listen 80;
    server_name storage.example.com;
    root /var/www/buckie/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### FrankenPHP

```bash
frankenphp php-server --root public/
```

### Docker (pruebas locales con Apache)

El repositorio incluye un `Dockerfile` y un `docker-compose.yml` listos para pruebas locales.

```bash

# 1. Construir la imagen e iniciar el contenedor
docker compose up --build -d

# 2. Verificar que el servicio está activo
curl http://localhost:8080/health

# 3. Crear un bucket (la ruta debe existir DENTRO del contenedor)
docker exec buckie-php php bin/buckie create bucket docs /var/buckie-storage/docs

# 4. Crear una identidad
docker exec buckie-php php bin/buckie create identity dev-user secret123

# 5. Otorgar permisos
docker exec buckie-php php bin/buckie grant dev-user docs --read --write --delete

# 6. Subir un archivo de prueba
curl -X PUT http://localhost:8080/docs/hello.txt \
  -H "X-Buckie-Identity: dev-user" \
  -H "X-Buckie-Secret: secret123" \
  -H "Content-Type: text/plain" \
  --data-binary "Hola desde Buckie PHP 🚀"

# 7. Descargar el archivo
curl http://localhost:8080/docs/hello.txt \
  -H "X-Buckie-Identity: dev-user" \
  -H "X-Buckie-Secret: secret123"

# 8. Detener el contenedor
docker compose down
```

Los datos persisten entre reinicios en `./docker-data/`:

```
docker-data/
├── buckie/          ← config.json, logs/, cache/
└── storage/         ← archivos subidos
```

**Variables de entorno disponibles** (configurables en `docker-compose.yml`):

| Variable | Default en Docker | Descripción |
|---|---|---|
| `BUCKIE_DATA_DIR` | `/var/buckie-data` | Config, logs y caché |
| `BUCKIE_STORAGE_DIR` | `/var/buckie-storage` | Almacenamiento de archivos |
| `BUCKIE_LOG_LEVEL` | `debug` | Nivel de log |

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `BUCKIE_DATA_DIR` | `<cwd>/.buckie` | Data directory (config, logs, cache) |
| `BUCKIE_HOST` | `0.0.0.0` | Bind host (development server only) |
| `BUCKIE_PORT` | `8080` | Bind port (development server only) |
| `BUCKIE_LOG_LEVEL` | `info` | Log level |

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
php bin/buckie create identity <identity> <secret>
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

Supported query parameters: `w` (width), `h` (height), `fit` (`cover` | `contain` | `fill`), `format` (`webp` | `jpeg` | `png`).

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
 └── cache/         # Reserved for future use
```

Each local bucket also maintains an internal directory:

```
/path/to/bucket/
 ├── tenant-a/
 │    ├── invoice.pdf
 │    └── 300x300/
 │         └── photo.webp   ← Cached thumbnail
 └── .buckie/
      └── staging/          ← Temporary upload staging area
```

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
