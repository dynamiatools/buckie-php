# Buckie PHP — project notes for Claude

Standalone PHP file server with an S3-style REST API (`public/index.php` behind Apache/Nginx/FrankenPHP) and a CLI (`bin/buckie`). It is **not** a library. Config lives in a JSON file, not a database.

## Language and style
- Code, comments, commit messages, PR descriptions, README and release notes are written in **English**.
- Every PHP file starts with `declare(strict_types=1);`. Match the existing style (PSR-4 `Buckie\` → `src/`).

## Environment and tests
- The dev machine may have no PHP. Run everything in Docker, mounting only this repo and running as the current user
  (`-u "$(id -u):$(id -g)"`), e.g. a `php:8.3-cli` image with `gd`, `fileinfo` and `pcntl`, then `vendor/bin/phpunit`.
- Run the **whole** suite before committing. Currently 168 tests; the concurrency test needs `pcntl`.
- Every security fix or behavior change needs a test, and the new test must fail against the old code.
- `tests/Support/TempDir.php` removes temp trees **without following symlinks**; use it for any test that creates symlinks.
- Also smoke-test the real Docker image (`docker build .`, then health, PUT/GET, auth, dotfiles) when touching `Dockerfile` or `docker/`.
- Runtime minimum is **PHP 8.1** (`readonly`, `never`); the suite needs 8.2+ (PHPUnit 11). Do not use 8.2+ syntax in `src/` or `bin/`.
- `.gitattributes` marks `tests/`, `docker/`, `phpunit.xml` and CI files `export-ignore`, so `git archive` and Packagist zips do not contain them. Use `git clone` when you need the full tree.

## Branches, commits and releases
- Work on a branch, never directly on `main`. Open a PR to `main`; CI (`.github/workflows/ci.yml`) must pass: PHP 8.2–8.4 tests, `composer validate --strict`, `composer audit`, and a PHP 8.1 syntax check.
- Commit messages: imperative subject, a short body explaining the *why*.
- **Release process** (publishing needs the owner's confirmation each time):
  1. Bump `ConfigService::VERSION` (the CLI `--version` reads it) in the PR, and refresh `composer.lock` if `composer.json` changed.
  2. Merge the PR, then create an **annotated tag without a `v` prefix** (`1.1.0`) on `main` and push it. Packagist indexes it automatically through the GitHub webhook; nothing in CI publishes.
  3. Create a **GitHub Release** for that tag (`gh release create <tag> --verify-tag --latest`) whose **title has the `v` prefix** (`v1.1.0`). Notes: security fixes, other changes, behavior changes, link to the PR.
  4. Check Packagist lists the new version.
- Never move or delete a published tag.

## Data directory and config
- Resolution order: `BUCKIE_DATA_DIR` → `<project root>/.buckie` → legacy `<cwd>/.buckie` only if it already holds a `config.json` (a warning is logged). New installs never use the cwd, because under FPM it is often the webroot.
- `config.json` holds bcrypt hashes: keep the data dir **outside the web root**. New files are 0600 / dirs 0700, but the mode and owner of an existing `config.json` are preserved when rewriting it (the web user may only be able to read it).
- All read-modify-write cycles go through `ConfigService::update()` (exclusive `flock`); never call `readConfig` + `writeConfig` by hand. Invalid JSON must throw, never be treated as an empty config (an empty file is still empty).
- The config format is shared with the Node.js implementation (`$2b$` bcrypt hashes, grants without `prefixes`). Do not change its shape without a migration path.

## Backward compatibility rules
Existing installations must keep working with their current config. Before changing behavior:
- Prefer clamping or ignoring bad input like 1.0.0 did over returning new errors; be strict only where input reaches the filesystem (thumbnail `format`) or can exhaust resources.
- Anything that can break an existing deployment gets an opt-in/opt-out env var **and** a row in the README "Upgrading from 1.0.0" table.
- Verify against an installation created by an older release (use its own CLI), not only with unit tests. `tests/BackwardCompatibilityTest.php` holds a 1.0.0-style config fixture.

## Security conventions (do not regress)
- Thumbnails: `format` is allow-listed (`webp`, `jpeg`/`jpg`, `png`); `w`/`h` are clamped to 4096; the cache lives in `<data dir>/cache/thumbnails/` under hashed names. Never build a file path from request input.
- `BucketService::resolveKey` is the only way to turn a key into a path: it blocks `..`, NUL bytes, the reserved `.buckie` dir (case-insensitive) and symlinks leaving the bucket (opt-in `BUCKIE_FOLLOW_SYMLINKS=1`).
- Grant prefixes match on path-segment boundaries (`/tenant-a` ≠ `/tenant-ab`); a trailing `/` is already a boundary.
- Check permission **before** looking up the bucket (no 404/403 oracle). `IdentityService::verify` always runs `password_verify`.
- Downloads carry `nosniff` and a sandbox CSP; HTML/SVG/JS are `attachment`. Error messages must not contain absolute paths.
- `X-Forwarded-For` is only trusted with `BUCKIE_TRUST_PROXY=1`. Uploads are unlimited by default; `BUCKIE_MAX_UPLOAD_BYTES` opts into a limit (413).
- Never put secrets on the command line in docs or examples: use STDIN / `BUCKIE_IDENTITY_SECRET` / `buckie provision`.

## Docker
- Apache image with a `buckie` wrapper (`runuser -u www-data`). Always run the CLI through it (`docker exec <c> buckie ...`); running as root creates files the web server cannot read.
- Apache needs `+FollowSymLinks` on `public/` for the `.htaccess` rewrite; do not switch it to `-FollowSymLinks`.
- Avoid `rm -rf` in Dockerfiles you pipe through scripted checks; use `--no-install-recommends` and leave apt lists.

## Deployment notes (shared hosting)
- Typical layout: document root is a symlink to `<project>/public`; the CLI and the web server run as the same user, so file ownership is not an issue.
- Order when upgrading: back up the data dir → update code and `composer install --no-dev -o` → verify `/health` and an authenticated GET → only then move the data dir out of `public/`.
- Before upgrading check `find <storage> -type l` (symlinks leaving a bucket) and grants whose prefix does not end in `/`.

## Change log summary
- **1.1.0** (2026-11-22): security hardening (thumbnail path injection, tenant prefix boundaries, symlink escape, config corruption/concurrency, upload limits, download headers, login timing, CLI secrets), Docker fix, first CI, PHP ≥ 8.1, README in English, full backward compatibility with 1.0.0 configs. See the GitHub Release and PR #1.
- **1.0.0**: initial release.
