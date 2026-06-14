# Lantern — context for Claude Code

Read `docs/PROJECT_BIBLE.md` first — it's the design-of-record (architecture,
security model, decisions, roadmap). `CHANGELOG.md` has the full v1.0.0→1.0.6
history. `SIGNING.md` covers the release/App Store path.

## What this is
Read-only git repository browser for Nextcloud, v1 = local server-side repos
only (NOT a GitHub/GitLab client; that's the v2 RemoteForgeProvider, not built).
Published at github.com/ExploitedBlackDuck/lantern, author Paul Ammann.

## Build / test / verify
- Build: `npm install && npm run build` → emits js/lantern-main.js, js/lantern-admin.js
  (entries are named `main`/`admin`; the config prepends `lantern-`. Do NOT
  rename to `lantern-main` or it double-prefixes and the frontend silently dies.)
- Test: `php tests/run-core-tests.php` (49 assertions, must stay green)
- Release: `make release` → build/release/lantern.tar.gz
- Live test: throwaway `nextcloud:34` Docker container; the stock image has NO git.

## Conventions / hard-won gotchas
- The git core (lib/Provider/Local/*) is framework-free on purpose — keep it testable.
- All git runs via proc_open arg-arrays (no shell) + RefValidator; never interpolate input.
- Every git call passes `-c safe.directory=<repoPath>`.
- Owner prefers direct, honest feedback; verify claims by running things, don't assert.

## Open work
- v1.x: branch picker, README rendering, @nextcloud/vue components, lazy-load hljs
  languages — DONE (bundle warning cleared); @nextcloud/vue deferred to live verify.
- v2: RemoteForgeProvider behind the existing IRepoProvider seam.
- See PROJECT_BIBLE §14 for the remaining [VERIFY] items.

# Lantern — context for Claude Code

This file orients an agent with no prior memory of the project. **Read
`docs/PROJECT_BIBLE.md` first** — it is the design-of-record (architecture,
full security model, decision records, roadmap, and the `[VERIFY]` ledger).
`CHANGELOG.md` is the complete v1.0.0→current history; `SIGNING.md` is the
release/App-Store path.

Everything above the "CURRENT STATE" section at the bottom is durable. The
bottom section goes stale — trust it least and update it as you work.

---

## What this is

Lantern is a **read-only** git repository browser for Nextcloud. v1 browses git
repositories **on the Nextcloud server's own filesystem** (e.g. `/srv/git/x`).

It is **NOT** a GitHub/GitLab client — it does not connect to remote forges.
Remote-forge browsing is the planned **v2 `RemoteForgeProvider`**, which slots
in behind the existing `IRepoProvider` seam without UI changes. It is not built.

Stack: PHP 8.1+ backend (Nextcloud App Framework), Vue 3 frontend, webpack via
`@nextcloud/webpack-vue-config`. Target: Nextcloud 30–34.

---

## Three different "where" contexts — do not conflate them

1. **Dev repo** (where you, the agent, work): the git working copy on the dev
   machine, e.g. `~/Downloads/lantern`. You edit, build, test, and commit here.
2. **Install location** (where the app runs): inside the Nextcloud server at
   `.../custom_apps/lantern` (or `apps/`). Deploying = copying the *runtime*
   files there, then `occ app:enable lantern`.
3. **Browsed repos** (the app's data): server-side git repos at paths like
   `/srv/git/...`, configured by an admin in the app's settings. These are NOT
   in the dev repo and NOT remote URLs.

Misconfiguring across these is the most common confusion (e.g. putting a dev
path in the admin repo JSON, or expecting a GitHub URL to work).

---

## Build / test / release commands

```bash
# Build the frontend (first time uses npm install; npm ci needs a lockfile)
npm install && npm run build      # emits js/lantern-main.js, js/lantern-admin.js

# Run the framework-free core test suite (must stay green)
php tests/run-core-tests.php      # 49 assertions; self-contained, needs only php+git

# PHPUnit wrapper (CI) — needs: composer require --dev phpunit/phpunit
# then: ./vendor/bin/phpunit

# Package an installable release artifact (build-free for end users)
make release                      # -> build/release/lantern.tar.gz (includes built js/)
```

`js/` is gitignored on purpose: the **source repo** does not commit build
output; the **release tarball** carries it. Don't force `js/` into git.

---

## HARD RULES / footguns (each of these actually bit us — do not relearn)

0. **Code-split (dynamic `import()`) chunks need an explicit
   `__webpack_public_path__`, or they 404 in a real install.** Webpack's
   `publicPath: 'auto'` resolves to `/apps/lantern/js/` (where NC *routes* the
   entry bundle Util::addScript requests), but lazy chunks are only served from
   the real dir `/custom_apps/.../lantern/js/`. Result: the chunk requests 404,
   the Markdown renderer + highlight.js languages silently fail, README shows
   "Could not load README." and code shows as plain text. Fix is at the top of
   the entry, before any dynamic import fires:
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

2. **Never write a file with `open(f,'w').write(open(f).read()...)`.** Python
   truncates the file the instant `open(...,'w')` is evaluated — before the
   inner read runs — so it reads empty and writes empty. This silently zeroed
   `info.xml` once and shipped a non-installable package. Always read fully into
   a variable, then open-for-write and write.

3. **`info.xml` is critical and easy to corrupt. Validate it** against the real
   schema before committing:
   ```bash
   curl -s -o /tmp/info.xsd https://apps.nextcloud.com/schema/apps/info.xsd
   xmllint --noout --schema /tmp/info.xsd appinfo/info.xml
   ```
   Element order matters to the XSD; "well-formed" is not enough.

4. **Every git invocation must pass `-c safe.directory=<repoPath>`** (see
   `GitBinary::run`). We disable system+global git config for safety, which also
   disables the only global fix for git's dubious-ownership guard; without the
   per-call flag, a repo owned by a different user than the web-server user
   fails and masquerades as empty. The repoPath comes from the registry's
   realpath'd, admin-configured value — never from request input.

5. **All git runs through `proc_open` with an argument ARRAY (no shell)** plus
   `RefValidator` allowlisting. Never interpolate a ref/path into a command
   string, and never add a code path that honors repo-controlled git config
   that can execute programs. **If you build v3 diffs**, `git diff`/`log -p`
   honor `diff.external`, `*.textconv`, and `.gitattributes` filters from the
   repo — generate diffs with `-c diff.external= -c core.attributesFile=/dev/null`
   (or a plumbing/libgit2 path) or a malicious repo gets code execution.

6. **Test fixtures must be deterministic and branch-pinned.** Pin the branch
   with `git symbolic-ref HEAD refs/heads/master` (don't rely on
   `init.defaultBranch`, which is `main` on most modern git/CI). Use fixed
   binary content with guaranteed NUL bytes, not `random_bytes` (random data
   lacks a NUL ~31% of the time, making binary-detection flaky).

7. **A version bump touches FOUR files — keep them in sync:**
   `appinfo/info.xml` (`<version>`), `package.json` (`version`), `CHANGELOG.md`
   (new entry), and `docs/PROJECT_BIBLE.md` (version header + "Current release"
   row). Missing one ships an inconsistent package.

8. **GitHub handle is `ExploitedBlackDuck`** (not `BlackDuckExploited` — the
   words swap easily and broke a push/URLs once). All repo URLs in `info.xml`,
   `SIGNING.md`, `CHANGELOG.md` use it.

---

## Code conventions (match what's there; don't impose new style)

- PHP: tabs for indentation, `declare(strict_types=1)` in every file, typed
  properties/params, `final` where practical.
- The **git core** (`lib/Provider/Local/*`, `lib/Model/*`, `lib/Exception/*`)
  is intentionally **framework-free** (no `OCP\…`) so it can be unit-tested with
  no Nextcloud install. Keep it that way; framework coupling lives in
  controllers/settings/bootstrap.
- The extension seam is `IRepoProvider`. New backends (e.g. remote forge)
  implement it and register in `Application::register`; nothing upstream (UI,
  controllers) should need to change.
- Frontend uses Nextcloud CSS design tokens (`var(--color-*)`), not a bespoke
  palette. It does **not** yet use `@nextcloud/vue` components (plain semantic,
  keyboard-operable elements) — adopting them is a v1.x item; don't claim native
  component conformance that isn't there.
- Controllers stay thin: resolve repo via registry → provider → return data;
  translate exceptions to HTTP statuses in one guard.

---

## Live testing recipe (throwaway NC 34 container)

The stock `nextcloud` image has **no git** and does not auto-install with
sqlite. This sequence works (adjust the app source path):

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

Gotchas seen live: NC's brute-force throttle (429) blocks repeated curl auth —
test the provider chain as `www-data` directly, or use an app password (token
auth is CSRF-exempt). The read API correctly returns **412 without a CSRF
token** — that's the Vue client's job to supply, not a bug. `occ app:check-code`
does **not** exist on NC 34. **OPCACHE:** the stock image runs mod_php with
opcache, so `docker cp`-ing changed PHP over an already-enabled app does NOT
take effect until you flush it — run `docker exec -u root <c> apachectl -k
graceful` after every redeploy (routes 404 / stale controllers otherwise). For
user-Files repos, run `occ files:scan <user>` after creating files on disk so NC
indexes them.

**Browser-test the frontend, not just the API.** curl verifies the JSON API but
never executes the Vue/webpack code, so it misses CSP/publicPath/chunk-loading
failures (see HARD RULE 0). Use a headless browser: `npm i -D puppeteer`, then a
script that logs in at `/login` (input[name=user]/[name=password]), opens
`/apps/lantern/`, and asserts on `#lantern` content while collecting
`console`/`pageerror`/`response>=400`. A green frontend = README renders as
Markdown, code shows `.hljs-*` spans, diff/blame/search work, and there are zero
console errors and zero 404s.

---

## Verification status (what's proven vs. assumed)

- **Backend: live-verified** on NC 34 — enable, provider chain end-to-end,
  security cases (traversal/ref-injection/type-confusion) all blocked, 27/27
  tests in-container.
- **Frontend: witnessed mounting** on live NC 34 after the entry-name fix; UI
  browses tree/blob/history and reads legibly on the themed background.
- **Not yet covered:** the narrow remaining `[VERIFY]` items in PROJECT_BIBLE
  §14 (live-server checks). The `RepoRegistry` unit test that used to live here is
  now done (stubbed `IAppConfig`/`LoggerInterface`; see `tests/registry-stubs.php`).

---

## Identity / secrets

- Author: **Paul Ammann**, email `lantern@pxsec.xyz`, GitHub **ExploitedBlackDuck**.
- Owner prefers **direct, honest feedback** and **verification by execution** —
  run things and report real results; don't assert success you haven't observed.
- The App-Store signing **private key must never be committed** (see
  `SIGNING.md`). `.gitignore` already excludes `node_modules/`, `js/`, `vendor/`.

---

## CURRENT STATE (stale-prone — update as you go)

- Version: **2.0.0**. The five-horizon build (H0–H4) is complete and
  **live-verified end-to-end on NC 34** (Docker): onboarding/empty-state + admin
  form (H0); README render, branch/tag picker, line numbers + permalinks,
  in-repo search, image preview/download, lazy bundle (H1); browse repos in a
  user's own Files (H2); browse **GitHub** repos behind the same seam, real-API
  verified (H3); diffs, blame, per-repo group restrictions, unified search,
  dashboard widget, deep links (H4). Suite: **73 assertions** green.
- Three repo sources, one UI, all behind `IRepoProvider`: admin server-side
  (`RepoRegistry`), user Files (`UserRepoStore`), GitHub (`Provider\Forge\GitHubProvider`
  + `ForgeRepoStore`, encrypted PAT). `GitBinary` hardened (`-c core.fsmonitor=
  core.hooksPath=/dev/null core.attributesFile=/dev/null diff.external=`).
- Remaining/optional: `@nextcloud/vue` adoption — **evaluated and declined for
  now** (would re-cross the bundle threshold H1f just cleared; ADR-006's
  themed+accessible bar is met). GitLab provider; commit-range diffs; sharing to
  other NC users; capture screenshots (README + info.xml `<screenshot>`); tag/
  sign for the App Store (owner's call).