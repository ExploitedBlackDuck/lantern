# Contributing & developer notes

Build gotchas, conventions, and the live-test recipe for working on Lantern.
Read `docs/PROJECT_BIBLE.md` first — it is the design-of-record (architecture,
full security model, decision records, roadmap, and the `[VERIFY]` ledger).
`CHANGELOG.md` is the complete version history; `SIGNING.md` covers the
release/App-Store path.

## What this is

Lantern is a **read-only** git repository browser for Nextcloud. It browses git
repositories on the Nextcloud server's own filesystem (e.g. `/srv/git/x`), a
user's own Nextcloud Files, and remote forges (GitHub, GitLab) — all behind a
single `IRepoProvider` seam. It is **not** a write client: read-only is the
load-bearing security simplification of the whole design (ADR-003).

Stack: PHP 8.1+ backend (Nextcloud App Framework), Vue 3 frontend, webpack via
`@nextcloud/webpack-vue-config`. Target: Nextcloud 30–34.

## Three different "where" contexts — do not conflate them

1. **Dev repo** — the git working copy on the dev machine. Edit, build, test,
   and commit here.
2. **Install location** — inside the Nextcloud server at `.../custom_apps/lantern`
   (or `apps/`). Deploying = copying the *runtime* files there, then
   `occ app:enable lantern`.
3. **Browsed repos** (the app's data) — server-side git repos at paths like
   `/srv/git/...`, configured by an admin in the app's settings, plus user-Files
   repos and remote forge repos. These are NOT in the dev repo.

Misconfiguring across these is the most common confusion (e.g. putting a dev
path in the admin repo JSON, or expecting a forge URL where a local path is
expected).

## Build / test / release commands

```bash
# Build the frontend (first time uses npm install; npm ci needs a lockfile)
npm install && npm run build      # emits js/lantern-main.js, js/lantern-admin.js

# Run the framework-free core test suite (must stay green)
php tests/run-core-tests.php      # 144 assertions; self-contained, needs only php+git

# PHPUnit wrapper (CI) — needs: composer require --dev phpunit/phpunit
# then: ./vendor/bin/phpunit

# Package an installable release artifact (build-free for end users)
make release                      # -> build/release/lantern.tar.gz (includes built js/)
```

`js/` is gitignored on purpose: the **source repo** does not commit build
output; the **release tarball** carries it. Don't force `js/` into git.

## Translations (i18n)

User-facing strings are translatable. PHP uses `$l->t()` / `$l->n()`; the front
end uses `t('…')` / `n('…','…',count)` (helpers in `src/l10n.js`, bound to the
`lantern` app id and registered on each Vue app in `src/main.js`/`src/admin.js`).

- Extract the source template: `make l10n` → `translationfiles/templates/lantern.pot`
  (requires GNU gettext; mirrors Nextcloud's official `translationtool.phar`).
- **English is the source language** and ships without a catalog — `t()` returns
  the source string when no catalog matches. Other locales compile to
  `l10n/<lang>.js` + `l10n/<lang>.json`, which Nextcloud auto-loads; **no code
  changes** are needed to add a locale. `make release`/`appstore` package `l10n/`.
- **Remaining work:** `EmptyState.vue` is fully externalized as the reference
  pattern. The other components (`App.vue`, `AdminApp.vue`, `BlobViewer.vue`,
  `CommitList.vue`, `RepoList.vue`, `RefPicker.vue`, `ForgeRepoManager.vue`,
  `MyReposManager.vue`, `GlobalSearchBox.vue`, `SearchBox.vue`, `TreeBrowser.vue`,
  `ReadmeView.vue`) and the PHP settings/setup-check/dashboard strings still hold
  inline English — wrap each in `t()` / `$l->t()` (mechanical; browser-verify
  after, per the discipline above).

## Build gotchas / footguns (each of these actually bit — do not relearn)

0. **Code-split (dynamic `import()`) chunks need an explicit
   `__webpack_public_path__`, or they 404 in a real install.** Webpack's
   `publicPath: 'auto'` resolves to `/apps/lantern/js/` (where Nextcloud *routes*
   the entry bundle `Util::addScript` requests), but lazy chunks are only served
   from the real dir `/custom_apps/.../lantern/js/`. Result: the chunk requests
   404, the Markdown renderer + highlight.js languages silently fail, README
   shows "Could not load README." and code shows as plain text. The fix is at the
   top of the entry, before any dynamic import fires:
   `import { generateFilePath } from '@nextcloud/router'; __webpack_public_path__ = generateFilePath('lantern', '', 'js/')`.
   **curl/HTTP checks CANNOT catch this — it only fails when a browser executes
   the imports. Always browser-test the frontend (see the puppeteer recipe).**

1. **Webpack entry names must be `main` / `admin`, NOT `lantern-main` /
   `lantern-admin`.** `@nextcloud/webpack-vue-config` sets
   `filename: ${appName}-[name].js` with appName=`lantern`, so entry `main` →
   `lantern-main.js`, which is what `Util::addScript('lantern','lantern-main')`
   requests. Naming the entry `lantern-main` produces `lantern-lantern-main.js`,
   and **Nextcloud silently omits a script whose file is missing — the entire
   frontend goes blank with no error.** After any webpack change, verify
   `ls js/` shows `lantern-main.js`/`lantern-admin.js` (single prefix).

2. **Cache-buster: after a frontend redeploy, bump the app version and run
   `occ upgrade` before visual verification.** Nextcloud's script cache-buster
   keys on the app version string, so a *same-version* redeploy serves stale JS
   to any warm-cache browser — and a fresh headless browser won't reveal it
   either (it just loads the cached asset by the unchanged versioned URL). Bump
   `<version>` (see the four-file sync rule below), run `occ upgrade`, then
   verify in the browser.

3. **Never write a file by reading and writing it in one truncating expression.**
   A `w`-mode open truncates the file the instant it is evaluated — before the
   inner read runs — so it reads empty and writes empty. This silently zeroed
   `info.xml` once and shipped a non-installable package. Always read fully into
   a variable, then open-for-write and write.

4. **`info.xml` is critical and easy to corrupt. Validate it** against the real
   schema before committing:
   ```bash
   curl -s -o /tmp/info.xsd https://apps.nextcloud.com/schema/apps/info.xsd
   xmllint --noout --schema /tmp/info.xsd appinfo/info.xml
   ```
   Element order matters to the XSD; "well-formed" is not enough.

5. **Every git invocation must pass `-c safe.directory=<repoPath>`** (see
   `GitBinary::run`). System+global git config is disabled for safety, which also
   disables the only global fix for git's dubious-ownership guard; without the
   per-call flag, a repo owned by a different user than the web-server user fails
   and masquerades as empty. The repoPath comes from the registry's realpath'd,
   admin-configured value — never from request input.

6. **All git runs through `proc_open` with an argument ARRAY (no shell)** plus
   `RefValidator` allowlisting. Never interpolate a ref/path into a command
   string, and never add a code path that honors repo-controlled git config that
   can execute programs. `git diff`/`log -p` honor `diff.external`, `*.textconv`,
   and `.gitattributes` filters from the repo — generate diffs with
   `-c diff.external= -c core.attributesFile=/dev/null` (or a plumbing/libgit2
   path) or a malicious repo gets code execution. Every git call also passes
   `-c core.fsmonitor= -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null -c diff.external=`.

7. **Test fixtures must be deterministic and branch-pinned.** Pin the branch with
   `git symbolic-ref HEAD refs/heads/master` (don't rely on `init.defaultBranch`,
   which is `main` on most modern git/CI). Use fixed binary content with
   guaranteed NUL bytes, not `random_bytes` (random data lacks a NUL ~31% of the
   time, making binary-detection flaky).

8. **A version bump touches FOUR files — keep them in sync:**
   `appinfo/info.xml` (`<version>`), `package.json` (`version`), `CHANGELOG.md`
   (new entry), and `docs/PROJECT_BIBLE.md` (version header + "Current release"
   row). Missing one ships an inconsistent package.

9. **GitHub handle is `ExploitedBlackDuck`** (not `BlackDuckExploited` — the words
   swap easily and broke a push/URLs once). All repo URLs in `info.xml`,
   `SIGNING.md`, `CHANGELOG.md` use it.

## Code conventions (match what's there; don't impose new style)

- PHP: tabs for indentation, `declare(strict_types=1)` in every file, typed
  properties/params, `final` where practical.
- The **git core** (`lib/Provider/Local/*`, `lib/Model/*`, `lib/Exception/*`) is
  intentionally **framework-free** (no `OCP\…`) so it can be unit-tested with no
  Nextcloud install. Keep it that way; framework coupling lives in
  controllers/settings/bootstrap.
- The extension seam is `IRepoProvider`. New backends (e.g. another forge)
  implement it and register in `Application::register`; nothing upstream (UI,
  controllers) should need to change.
- Frontend uses Nextcloud CSS design tokens (`var(--color-*)`), not a bespoke
  palette. It does **not** yet use `@nextcloud/vue` components (plain semantic,
  keyboard-operable elements) — adopting them is a deferred item; don't claim
  native component conformance that isn't there.
- Controllers stay thin: resolve repo via registry → provider → return data;
  translate exceptions to HTTP statuses in one guard.

## Live testing recipe (throwaway NC 34 container)

The stock `nextcloud` image has **no git** and does not auto-install with sqlite.
This sequence works (adjust the app source path):

```bash
docker run -d --name nc34 -p 8080:80 nextcloud:34
sleep 45
docker exec -u www-data nc34 php occ maintenance:install --admin-user admin --admin-pass admin
docker exec -u www-data nc34 php occ config:system:set trusted_domains 1 --value=localhost
docker exec -u root nc34 bash -c "apt-get update -qq && apt-get install -y git"   # image lacks git
docker cp ./lantern nc34:/var/www/html/custom_apps/lantern
docker exec -u root nc34 chown -R www-data:www-data /var/www/html/custom_apps/lantern
docker exec -u www-data nc34 php occ app:enable lantern
# UI at http://localhost:8080  (admin/admin).  Tear down: docker rm -f nc34
```

Gotchas seen live:

- **opcache:** the stock image runs mod_php with opcache, so `docker cp`-ing
  changed PHP over an already-enabled app does NOT take effect until it is
  flushed — run `apachectl -k graceful` after every redeploy (routes 404 / stale
  controllers otherwise).
- **version bump → upgrade:** changing `info.xml <version>` puts Nextcloud in
  "upgrade required" mode; run `occ upgrade` (see the cache-buster note above).
- **user-Files repos:** run `occ files:scan <user>` after creating files on disk
  so Nextcloud indexes them.
- **brute-force throttle:** repeated curl auth can trip Nextcloud's protection
  (429/401); test as `www-data` directly or use an app password (token auth is
  CSRF-exempt). The read API correctly returns **412 without a CSRF token** —
  that's the Vue client's job to supply, not a bug. `occ app:check-code` does
  **not** exist on NC 34.

**Browser-test the frontend, not just the API.** curl verifies the JSON API but
never executes the Vue/webpack code, so it misses CSP/publicPath/chunk-loading
failures (see gotcha 0). Use a headless browser: `npm i -D puppeteer`, then a
script that logs in at `/login` (`input[name=user]`/`[name=password]`), opens
`/apps/lantern/`, and asserts on `#lantern` content while collecting
`console`/`pageerror`/`response>=400`. A green frontend = README renders as
Markdown, code shows `.hljs-*` spans, diff/blame/search work, and there are zero
console errors and zero 404s. If the default `headless: 'new'` Chromium crashes
renderers (environmental), use `headless: 'shell'` (chrome-headless-shell).

## Identity / secrets

- Author: **Paul Ammann**, email `lantern@pxsec.xyz`, GitHub **ExploitedBlackDuck**.
- The App-Store signing **private key must never be committed** (see `SIGNING.md`).
  `.gitignore` already excludes `node_modules/`, `js/`, `vendor/`.
- Verify claims by running things — report real, observed results rather than
  assumed ones.
