# Lantern

**Browse the git repositories on your Nextcloud server — right inside Nextcloud.**

Lantern is a read-only web browser for git repositories that live **on your
Nextcloud server's own disk**. Browse the file tree, read files with syntax
highlighting, and view commit history — all behind your existing Nextcloud
login, with no separate forge to run.

It's built for self-hosters and admins who already keep git repositories on
their server (deploy checkouts, scripts, notes, bare repos) and want to *view*
them in the web UI without standing up a full Gitea/Forgejo alongside Nextcloud.

![Lantern browsing a repository](docs/screenshot-files.png)

## What Lantern is — and isn't (please read before installing)

- ✅ It browses git repos **stored on the Nextcloud server's filesystem** (e.g.
  `/srv/git/myrepo`), configured by an administrator.
- ❌ It is **not** a GitHub/GitLab client. You cannot point it at a remote
  `github.com/...` URL — it does not connect to remote forges. *(Remote-forge
  browsing is planned for v2; the architecture already has the seam for it, but
  it is not built yet.)*
- ❌ It is **read-only**. No commits, pushes, or edits — by design.
- v1 scope: file tree, file viewer, commit history. No diffs, no branch
  switching in the UI yet (both on the roadmap).

If you were hoping to browse your GitHub repositories, that's the v2 goal — not
this release.

## Requirements

- Nextcloud 30–34.
- The **`git` binary installed and runnable by the web-server user.** The
  official Nextcloud Docker image does **not** ship git — install it in the
  container (or set an absolute path in Lantern's admin settings). Lantern's
  setup check warns you under Administration → Overview if git is missing.

## Install

```bash
npm install && npm run build      # build the frontend (creates js/)
# copy this folder into your Nextcloud custom_apps/ (or apps/) directory, then:
php occ app:enable lantern
```

Then, as an admin, go to **Settings → Administration → Lantern** and add
repositories as a JSON array of `{id, name, path}` — for example:

```json
[{ "id": "scripts", "name": "Deploy scripts", "path": "/srv/git/scripts" }]
```

You can optionally confine all paths to an `allowed_base` directory, and set an
absolute git path if `git` isn't on the web server's `PATH`.

## Status

Verified on a live Nextcloud 34 install: enables cleanly, the UI mounts, the
provider chain works end to end, and the security cases (path traversal, ref
injection, type confusion) are all blocked. The framework-free git core is
covered by a committed test suite:

```bash
php tests/run-core-tests.php       # 27 assertions (functional + security + edge)
```

## Security notes

Lantern runs `git` as a subprocess with no shell (argument arrays, never a
command string), validates every ref and path against strict allowlists, and
only browses repositories an administrator has explicitly configured. Because a
repository's own `.git/config` is always honored by git, **only point Lantern at
trusted, admin-controlled repositories** — not at user-writable ones. See
`docs/PROJECT_BIBLE.md` §9 for the full threat model.

## Documentation

Full design, security model, API contract, decision records, and roadmap:
**[docs/PROJECT_BIBLE.md](docs/PROJECT_BIBLE.md)**. Release/signing steps:
**[SIGNING.md](SIGNING.md)**.

## License

AGPL-3.0-or-later — see [COPYING](COPYING).
