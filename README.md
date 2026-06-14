# Lantern

A read-only **git repository browser for Nextcloud**. Browse the file tree, read
files with syntax highlighting, and view commit history for repositories that
live on your Nextcloud server's disk — behind your existing login, with no
external forge required.

This is **v1.0.1**: local repositories, read-only. A pluggable provider interface
is in place so remote-forge browsing (GitHub/GitLab) can be added later without
touching the UI.

## Status

- Security-critical git core: **framework-free and tested** — run
  `php tests/run-core-tests.php` (27 assertions: functional, edge cases, and
  injection/traversal/type-confusion).
- Nextcloud-coupled layer (controllers, settings, DI): written for NC 30–33,
  lints clean, **not yet run on a live server** — see the verification ledger
  in `docs/PROJECT_BIBLE.md` §14, and the review-response changes in `CHANGELOG.md`.

## Quick start

```bash
npm ci && npm run build          # build the frontend
# place in your Nextcloud apps/ dir, then:
php occ app:enable lantern
```

Then configure repositories under **Settings → Administration → Lantern** as a
JSON array of `{id, name, path}`, optionally confined to an `allowed_base`
directory.

## Documentation

The full design, security model, API contract, decision records, and roadmap
live in **[docs/PROJECT_BIBLE.md](docs/PROJECT_BIBLE.md)**.

## License

AGPL-3.0-or-later.
