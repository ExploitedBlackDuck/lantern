# Lantern — Development Journal

**As of 2026-06-14 · App version 2.0.0**

A running record of the 1.0.6 → 2.0.0 build: what was done, how it was verified,
the local test environment, and what's left. For the durable design-of-record
see `docs/PROJECT_BIBLE.md`; for the full change history see `CHANGELOG.md`.

---

## 1. Where we started and where we are

- **1.0.6** was a proof-of-concept: it browsed only admin-configured server-side
  repos, with no onboarding and no remote/forge support. A new user (the author
  included) had no obvious way to use it, and expected — but couldn't — point it
  at GitHub.
- **2.0.0** is a real, multi-source, read-only git browser delivered as five
  "horizons", **live-verified end-to-end on Nextcloud 34** (Docker, PHP 8.5,
  git 2.47) including a real headless-browser pass.

One UI, three repository sources, all behind the single `IRepoProvider` seam:

| Source | Backend | How a user adds it |
| --- | --- | --- |
| Admin server-side repos | `Service/RepoRegistry` → `LocalGitProvider` | Admin settings form |
| A user's own Nextcloud Files | `Service/UserRepoStore` → `LocalGitProvider` | Sidebar "Add a repo from your Files" |
| GitHub | `Provider/Forge/GitHubProvider` + `Service/ForgeRepoStore` | Sidebar "Add a GitHub repository" |

---

## 2. What was built (by horizon)

- **H0 — Onboarding & trust:** guided first-run/empty state (`EmptyState.vue`);
  admin repo form with a per-row "Test path" check (`AdminApp.vue`,
  `POST /settings/validate-path`); honest README.
- **H1 — Reading experience:** README rendering (markdown-it + DOMPurify);
  branch/tag picker; line numbers + `#L20-L42` line-range permalinks; in-repo
  search (`git grep`); image preview + raw download; **lazy-loaded bundle**
  (480→~200 KiB, webpack size warning gone). Seam gained `listRefs`,
  `getBlobRaw`, `search`. Long-missing `RepoRegistry` unit test added.
- **H2 — Your own Files:** browse a `.git` repo inside a user's Nextcloud Files;
  resolved to a local path, confined to the user's Files, non-local storage
  refused. **Security gate (§9.6a):** `GitBinary` hardened so an untrusted
  repo's config can't execute code.
- **H3 — GitHub:** browse remote GitHub repos behind the same seam; per-user
  repos with **encrypted** personal access tokens (`ICrypto`); **pagination
  baked into the contract** (`listCommits($offset)` + "Load more") before the
  second implementer landed. Mapping logic is pure/static with fixture tests.
- **H4 — Native integration + diffs/blame:** commit **diffs** (colour-coded) and
  **blame**; **per-repo group restrictions** (admin); **unified search** entries
  in NC's global search; **dashboard widget** (recent commits); **deep links**
  (`?repo=&ref=&path=&blob=#L..`) so file/line views are shareable.

**Security hardening (`GitBinary`):** every git call now also passes
`-c core.fsmonitor= -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null -c diff.external=`,
neutralising the repo-config directives that can execute a program (covers the
untrusted user-Files case and the diff feature's RCE constraint, §9.6).

---

## 3. Verification status

- **Backend:** `php tests/run-core-tests.php` → **73 assertions, all passing**
  (functional + security + the new refs/raw/search/pagination/diff/blame, the
  RepoRegistry containment incl. the `/srv/git-evil` false-prefix case, and 12
  GitHub JSON→model fixture assertions). Lint clean.
- **Live API (NC 34, token auth):** provider chain, every endpoint, binary
  suppression, path omission, all injection/traversal/type-confusion cases
  blocked (4xx). H2 add/browse/remove of a Files repo. H3 browsed
  **real `octocat/Hello-World`** (refs/tree/blob). H4 diff/blame/search/widget.
- **Browser (headless Chrome / puppeteer):** README renders as Markdown; line
  numbers + syntax highlighting; blame gutter; commit diffs; in-repo search;
  group restrictions with a real non-member user — **all OK, zero console
  errors, zero 404s.**

### Notable bug caught in browser verification
Lazy-loaded chunks 404'd in a real install: webpack `publicPath: 'auto'` pointed
at `/apps/lantern/js/` (where NC routes the entry bundle) but the split chunks
are served from `/custom_apps/.../js/`. This broke README rendering and silently
dropped syntax highlighting to plain text. **curl/HTTP checks could not catch it
— only a browser executing the code-split imports does.** Fixed by pinning
`__webpack_public_path__ = generateFilePath('lantern','','js/')` in `src/main.js`
(see CLAUDE.md HARD RULE 0). Re-verified green in the browser.

---

## 4. Local test environment (Docker NC 34)

A throwaway Nextcloud 34 container is the live test/verify target.

- **URL:** http://localhost:8099
- **Admin:** `admin` / `admin_pass_123`
- **Container:** `nc-lantern` · **app installed:** `lantern 2.0.0`
- A test fixture repo (`Test Repo` → `/srv/git/testrepo`, root-owned to exercise
  `safe.directory`) is configured. It is *test data*, not part of the app —
  remove it in Settings → Administration → Lantern.

### First-time bring-up (if the container is gone)
```bash
docker run -d --name nc-lantern -p 8099:80 nextcloud:34
# wait for /var/www/html/occ to exist, then:
docker exec -u www-data nc-lantern php occ maintenance:install --database sqlite \
  --admin-user admin --admin-pass admin_pass_123
docker exec -u www-data nc-lantern php occ config:system:set trusted_domains 1 --value=localhost
docker exec -u root nc-lantern bash -c "apt-get update -qq && apt-get install -y git"  # image ships no git
```

### Load the latest app build into the container (redeploy)
```bash
cd /Users/paul/Claude/Projects/lantern
npm install                 # first time only
npm run build               # emits js/lantern-main.js, js/lantern-admin.js + lazy chunks
make release                # stages runtime-only files into build/release/lantern
docker cp build/release/lantern/. nc-lantern:/var/www/html/custom_apps/lantern/
docker exec -u root nc-lantern chown -R www-data:www-data /var/www/html/custom_apps/lantern
docker exec -u root nc-lantern apachectl -k graceful   # MUST flush opcache, else stale PHP / 404 routes
docker exec -u www-data nc-lantern php occ app:enable lantern
# if the <version> changed: docker exec -u www-data nc-lantern php occ upgrade
```

### Gotchas (each actually bit during this build)
- **opcache:** after `docker cp` of changed PHP, run `apachectl -k graceful` or
  routes 404 / controllers run stale code.
- **user-Files repos:** run `occ files:scan <user>` after creating files on disk
  so NC indexes them.
- **version bump → upgrade:** changing `info.xml <version>` puts NC in
  "upgrade required" mode; run `occ upgrade`.
- **brute-force throttle:** repeated curl auth can trip NC's protection (429/401);
  test as `www-data` directly or use an app password (token auth is CSRF-exempt).

### Browser-test recipe (don't trust curl alone for the frontend)
```bash
npm i -D puppeteer   # bundles Chromium
# script: log in at /login (input[name=user]/[name=password]), open
# /apps/lantern/, assert on #lantern content, collect console errors +
# responses >=400. Green = content renders AND zero console errors AND zero 404s.
```

### Tear down
```bash
docker rm -f nc-lantern
```

---

## 5. Decisions made

- **`@nextcloud/vue` adoption — evaluated and declined (for now).** It would
  re-cross the bundle-size threshold H1 just cleared, and ADR-006's
  "themed + accessible" bar is already met with NC CSS design tokens + semantic,
  keyboard-operable elements. Revisit if NC externalises the component library.
- **User-Files browsing shipped as a per-user repo *source*, not a separate
  provider class** — once a Files folder is resolved to a local path the read is
  identical, and the hardened `GitBinary` makes it safe.
- **GitHub auth = personal access token** (not OAuth) for v2 — simpler, works for
  public (no token) and private (token) repos.

---

## 6. Known limitations / follow-ups

- **Screenshots** still need capturing from a browser for the README and the
  `info.xml <screenshot>` URL (the latter is required for the App Store).
- **GitLab** provider (same pattern as GitHub) — not built.
- **Commit-range diffs** (commit-to-commit) and **GitHub blame** (REST has none;
  would need GraphQL) — not built; single-commit diff + local blame work.
- **Sharing to other NC users** with per-user permission checks beyond group
  visibility — not built (deep links + group restrictions are in).
- **App Store**: tag `v2.0.0`, sign, and submit — owner's call (see SIGNING.md).
- Not committed to git: this working directory is not a git repo; nothing has
  been committed/pushed. Commit when ready.
