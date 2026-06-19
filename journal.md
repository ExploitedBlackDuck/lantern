# Lantern — Development Journal

**As of 2026-06-19 · App version 2.3.0**

A running record of the 1.0.6 → 2.3.0 build: what was done, how it was verified,
the local test environment, and what's left. For the durable design-of-record
see `docs/PROJECT_BIBLE.md`; for the full change history see `CHANGELOG.md`; for
build gotchas and the live-test recipe see `CONTRIBUTING.md`.

> **2.0.1 → 2.2.2 (post-2.0 work) is summarised in §7;** the **2.3.0 hardening
> pass is in §8 at the bottom** (current state — trust it most). §§1–6 describe
> the 2.0.0 build and remain accurate except where §7/§8 supersede them.

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
| GitHub | `Provider/Forge/GitHubProvider` + `Service/ForgeRepoStore` | Sidebar forge picker → GitHub |
| GitLab (incl. self-hosted) | `Provider/Forge/GitLabProvider` + `Service/ForgeRepoStore` | Sidebar forge picker → GitLab *(2.1.0, §7)* |

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
(see CONTRIBUTING.md build gotcha 0). Re-verified green in the browser.

---

## 4. Local test environment (Docker NC 34)

A throwaway Nextcloud 34 container is the live test/verify target.

- **URL:** http://localhost:8099
- **Admin:** `admin` / `Lantern-Verify-2026-xZ` *(reset 2.2.0; NC password
  policy rejects weak/compromised values, hence the strong string)*
- **Container:** `nc-lantern` (port 8099) · **app installed:** built from `main`
  (2.2.x; `main` is 2.2.2). Redeploy via the recipe below after a `git pull`.
- Configured *test data* repos (not part of the app; remove in Settings →
  Administration → Lantern): `Test Repo` → `/srv/git/testrepo` (root-owned, to
  exercise `safe.directory`), plus `Alpha` → `/srv/git/alpha` (3 commits) and
  `Beta` → `/srv/git/beta` (2 commits), both containing the word "needle" so
  cross-repo search has multiple repos to span.

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
cd lantern                  # the dev working copy
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

- ~~**Screenshots**~~ — **captured in 2.2.2 (§7):** README + three
  `info.xml <screenshot>` entries (raw `main` URLs resolve).
- ~~**GitLab** provider~~ — **built in 2.1.0 (§7).**
- ~~**Commit-range diffs**~~ — **built in 2.2.0 (§7).** **GitHub blame** (REST
  has none; would need GraphQL) is still unbuilt; GitLab blame *does* work
  (its REST exposes it). Local blame works.
- **Sharing to other NC users** with per-user permission checks beyond group
  visibility — not built (deep links + group restrictions are in).
- **App Store**: tag, sign, and submit — owner's call (see SIGNING.md).
- See §7 for the remaining post-2.0 follow-ups (with-token private GitLab path,
  forge ref pagination beyond 100).

---

## 7. Post-2.0 work (2.0.1 → 2.2.2)

Driven by `ROADMAP.md`: close the v2 hardening gate (§0), then the highest
value-to-effort features (§1 Tier 1 GitLab, Tier 2 diffs/search), then a
UI/IA cleanup and polish. This working directory **is** a git repo (the §6
"not committed" note was stale). Work shipped as four PRs, **all now merged to
`main`** (stacked PRs were base-retargeted to `main` as each landed so diffs
didn't double-count; feature branches deleted):

- **PR #1** 2.0.1 hardening + 2.1.0 GitLab → `main`.
- **PR #2** 2.2.0 Tier 2 (diffs + cross-repo search) → `main` (was stacked).
- **PR #3** 2.2.1 UI/IA cleanup → `main` (was stacked).
- **PR #4** 2.2.2 full-width fill + screenshots → `main`.

`main` is at **2.2.2**; merge commits `f78c2a7` → `1a74e35` → `2cdfdef` →
`ce3a4cc`. (If `gh` isn't logged in locally, populate `GH_TOKEN` from the git
credential helper for `gh` commands.)

### 2.0.1 — hardening (ROADMAP §0, "feature zero")
- **Honest GitHub failure states.** Every non-2xx used to collapse into
  `RepoNotFoundException`, so a rate-limit or a bad token read as "Not found."
  Now: 429 / 403-with-quota-exhausted-or-`Retry-After` → `RateLimitException`
  (HTTP 429, message names the reset time); 401/403 → `ForgeAuthException`
  (502, "fix the token"); generic/transport → 502. The decision moved into the
  **pure static `GitHubProvider::classifyStatus()`** (+ `pageFor()`) so it's
  fixture-tested, not live-only. Mapped in `RepoController::guard()`/`raw()`.
- **Trust-boundary regression test.** A repo-controlled malicious
  `diff.external` is proven *real* (plain `git diff` runs it) and proven
  *neutralised* by the `GitBinary` hardening flags (§4/§9.6 standing rule).
- Real-repo edge coverage: detached HEAD, no-commit/unborn, single-ref.

### 2.1.0 — GitLab provider (ROADMAP §1 Tier 1)
- `Provider/Forge/GitLabProvider` (REST v4) behind the same `IRepoProvider`
  seam — **no controller/frontend rewrite.** Tree, blob, history, branch/tag
  picker, commit diffs, code search, **plus blame and line-numbered search**
  (GitLab's REST exposes them; GitHub's doesn't without GraphQL).
- **Self-hosted instances** (per-repo base URL, default gitlab.com) and
  **nested project paths** (`group/sub/project`, URL-encoded into `:id`). PAT
  via `PRIVATE-TOKEN`, encrypted at rest. Same error contract as GitHub.
- `ForgeRepoStore` **generalised** to `{kind, host, slug}` with backward-
  compatible reading of pre-2.1 GitHub rows (no migration). Add-repo UI gained
  a GitHub/GitLab picker.
- **Live-verified against the real gitlab.com API** via the new, network-gated
  `tests/live-gitlab.php` (not in the offline suite). The pass found + fixed two
  real behaviours: (1) GitLab blob-search needs auth **even for public
  projects** (401 anon) → `search()` degrades to `[]` when tokenless;
  (2) branch/tag lists are bounded at `per_page=100` (same as GitHub) so the
  default can page out of the picker — `defaultRef()` is fetched independently
  so browsing is unaffected.

### 2.2.0 — commit-range diffs + cross-repo search (ROADMAP §1 Tier 2)
- **Commit-range diffs:** `getRangeDiff()` on the interface + all three
  backends — local `git diff --no-ext-diff` (the canonical disable of external
  diff drivers; `git diff`, unlike `git show`, *honors* `diff.external`),
  GitHub/GitLab compare endpoints. `CommitList` compare mode: pick one commit's
  "⇄ compare", then another; ordered older→newer so additions read as `+`.
  New `GET /api/repos/{id}/diff-range`.
- **Cross-repo search:** new `GET /api/search` aggregates one query across every
  repo the user can see, grouped by repo, each hit clickable to file+line.
  Bounded fan-out (caps repos/query + matches/repo; a failing/rate-limited repo
  is skipped, never fatal; truncation surfaced). New `GlobalSearchBox` + results
  view in `App.vue`.

### 2.2.1 — UI / IA cleanup (UI_CLEANUP_SPEC)
Presentation only. Fixed the "everything is a grey pill" problem — its root
cause was Nextcloud's bare-`<button>` background rule
(`button:not(.button-vue,…)`, specificity 0,1,1) beating our single-class
resets; an `#lantern`-scoped reset now wins cleanly (no `!important`). Breadcrumb
is true path text; the file tree renders as rows with the open file tinted +
`aria-current`; the branch picker and in-repo search moved into a main-pane
toolbar (sidebar keeps only global search + repo list + add buttons); the active
repo is an unmistakable chip with `aria-current`. Browser-verified on NC 34:
18/18, zero console errors, zero 404s. The "GitHub or GitLab" label was already
correct (GitLab shipped in 2.1.0).

### 2.2.2 — full-width fill + screenshots
Found while capturing screenshots: the app didn't fill wide screens — the NC
desktop wallpaper bled in on the right. Diagnosed via computed widths (`#lantern`
**883px** inside a **1264px** `#content`): NC's `#content` is a flex row and
`#lantern` was a flex item with no `flex`/`width`, so it shrank to content width.
Fix: `flex: 1 1 auto; width: 100%; min-width: 0` → confirmed in-browser
(**1264 = 1264**). Replaced the pre-cleanup placeholder with three fresh
screenshots (files / commit-range compare / cross-repo search) in `docs/`, wired
into the README and `info.xml <screenshot>` (3 entries; raw `main` URLs return
200, so the App-Store screenshot follow-up is unblocked). README now lists
GitLab as a source.

> **Tooling gotcha (new):** the default `headless: 'new'` Chromium crashed
> renderers this session — even a bare login screenshot failed (environmental,
> not app-related). `headless: 'shell'` (chrome-headless-shell) was stable and
> was used for all verification + capture. Use it for the puppeteer recipe if
> 'new' is flaky.

### Verification (cumulative)
- **Offline suite: 73 → 144 assertions**, all green
  (`php tests/run-core-tests.php`) — GitHub + GitLab mappers, both error
  contracts, pagination, malformed/empty responses, real-repo edges, and the
  range-diff `diff.external` RCE block. Re-run green on `main` post-merge.
- **Live gitlab.com: 20/20** (`php tests/live-gitlab.php` — network-gated).
- **Browser (headless Chromium) on the live NC 34 container:** Tier 2 **16/16**;
  UI cleanup + width fix **18/18** (toolbar placement, breadcrumb/file-rows not
  pills via computed-bg checks, open-file tint, active-repo chip + `aria-current`,
  full-width fill). **Zero console errors, zero page errors, zero
  `/apps/lantern` 4xx/5xx** throughout. (HARD RULE 0 satisfied.)

### Remaining follow-ups
- With-token **private** GitLab project path (needs a real PAT; the NC
  `IClientService` wiring mirrors the live-verified GitHub backend).
- Forge **ref pagination beyond 100** branches/tags (affects both GitHub and
  GitLab pickers).
- GitHub blame (GraphQL); per-user sharing; **App-Store submission** (tag/sign/
  submit — screenshots are now ready and hosted, so this is unblocked).

---

## 8. Hardening pass (2.3.0) — current state

2.3.0 makes ROADMAP §0/§2 concrete: turn a tool that runs on one instance into
an app strangers can install. **No new sources or write capability** — robustness,
scale, and submission mechanics only. Every item below was **live-verified on a
throwaway Nextcloud 34 container** (see §8.5) in addition to offline tests.

### 8.1 Real-repo edge cases (the things that break on strangers' repos)
- **Git LFS pointers.** New pure detector `lib/Provider/LfsPointer.php` recognises
  the pointer format (`version https://git-lfs…` + `oid sha256:` + `size`). The
  Local, GitHub, and GitLab blob paths now suppress the pointer text and mark the
  blob as LFS (`BlobContent` gained `lfs` / `lfsOid` / `lfsSize`). `BlobViewer`
  shows a "Stored with Git LFS" notice and no longer tries to render an
  LFS-backed image from its pointer bytes.
- **Submodules.** A `commit`-type tree entry maps to `TreeEntry::TYPE_COMMIT` in
  the forge providers (the local provider already passed it through); the file
  tree renders it as a submodule reference (disabled row), not a broken folder.
- **Bug the live pass caught that offline tests could not:** the `raw` download
  endpoint lacked `#[NoCSRFRequired]`, so `<img>` tags and download links got a
  `412` — image preview and binary download were broken. Fixed (it is a
  read-only, session-authenticated GET; nosniff + strict CSP already applied).
  *Lesson reaffirmed: browser-test the frontend; HARD RULE 0 territory.*

### 8.2 Caching (correctness + cost at scale)
`lib/Provider/Cache/CachingRepoProvider.php` decorates every `IRepoProvider` and
memoises the expensive reads (tree/blob/commits/refs/diff/blame/search) in NC's
`ICache` (`ICacheFactory::createDistributed`, which **no-ops safely without
Redis/APCu**). Wired in `Application::register`. **Keys are namespaced per user**
because forge/user-Files repo ids are per-user and the distributed cache is
shared — a global key would leak one user's private repo content to another. TTLs
are short (refs move); raw byte streams pass through uncached; oversized blob
bodies/diffs and **errors are never cached** (a failed read throws, so only
successes reach the cache).

### 8.3 Internationalization (translation-ready; ships English)
- `src/l10n.js` exposes `t()` / `n()` bound to the `lantern` app id (via
  `@nextcloud/l10n`), registered on both Vue apps (`main.js`, `admin.js`) so
  templates call `t('…')` directly.
- **All** front-end components and the user-facing PHP (`$l->t()` in the setup
  check, dashboard widget, search provider, admin section) are externalized.
- `make l10n` extracts PHP + front-end strings into
  `translationfiles/templates/lantern.pot` (mirrors NC's `translationtool.phar`).
  English is the source language and needs no catalog; other locales drop into
  `l10n/<lang>.{js,json}` with **no code changes**. `make release`/`appstore`
  package `l10n/`.

### 8.4 Accessibility (WCAG AA in light *and* dark)
The syntax-highlight palette used to brighten via `@media (prefers-color-scheme:
dark)`, which follows the **OS** — but NC's dark theme is a per-user **app**
setting, so a user on NC-dark + light-OS got light-tuned token colors on a dark
background (axe measured 2.0–2.6:1). Reworked into theme-switchable CSS custom
properties keyed off NC's own theme attributes (`data-theme-dark` /
`data-theme-default` + `prefers-color-scheme`), so a forced NC theme wins and all
values clear AA. **axe reports 0 critical/serious on the tree and file views in
both themes.** Manual residual: keyboard-only walkthrough + screen-reader smoke.

### 8.5 Verification (cumulative)
- **Offline suite: 144 → 173 assertions**, all green (`php tests/run-core-tests.php`)
  — adds LFS-pointer detection (pure + Local/forge), submodule mapping, and the
  caching decorator (a counting fake proves hits/misses, the size cap, and the
  no-cache-on-error contract).
- **Live on NC 34:** LFS/submodule end-to-end **13/13** (`tests/live-verify-lfs.cjs`),
  axe **0 critical/serious** in light and dark (`tests/a11y-scan.cjs`) — both are
  **network-gated helpers, not in the offline suite**. Zero console errors, zero
  `/apps/lantern` 4xx/5xx throughout.

### 8.6 Local test environment (current)
Container **`nc-lantern`** (NC 34.0.0, sqlite) on **http://localhost:8099**,
admin **`admin` / `admin_pass_123`**, running the **2.3.0 release tarball**
(installed via `occ upgrade`, not a dev mount). Two demo repos under `/srv/git`:
- **`demo-app`** — README + Python/JS sources, three commits (diffs/blame), a
  `feature/widgets` branch and `v1.0.0` tag (ref picker), and the word `needle`
  in two files (cross-repo search).
- **`lfsdemo`** — `big.png` (LFS pointer), `real.png` (real image),
  `vendored-lib` (submodule gitlink) — exercises §8.1.
Bring-up recipe and gotchas are in `CONTRIBUTING.md` (note the version-bump →
`occ upgrade` cache-buster rule). Tear down: `docker rm -f nc-lantern`.

### 8.7 App-Store submission — status & next steps (continuity)
Four-file version sync done (info.xml/package.json/CHANGELOG/PROJECT_BIBLE);
`info.xml` validates against the live schema; the three screenshot URLs resolve.

- **Stage 1 (GitHub release): DONE.** `v2.3.0` tagged with a clean
  `lantern.tar.gz` asset (also the public download URL the store fetches).
- **Signing keypair: DONE.** `~/.nextcloud/certificates/lantern.{key,csr}`,
  `CN=lantern`. **`lantern.key` is secret — keep it out of git and back it up;**
  losing it voids any issued certificate. See `SIGNING.md`.
- **Certificate request: PREPARED, not yet submitted.** The CSR is staged for a
  PR to `nextcloud/app-certificate-requests` (path `lantern/lantern.csr`).
  **Blocked on a precondition:** the GitHub profile needs a public email before
  the request will be processed. Once set, open the PR; Nextcloud's team reviews
  and issues `lantern.crt` (days).
- **Then (needs the cert + a Nextcloud account):** `occ integrity:sign-app`
  (writes `appinfo/signature.json`) → repackage → recompute the release
  signature → upload via the "Submit your app" form / REST API on
  apps.nextcloud.com. The app id **`lantern`** is confirmed available.

### 8.8 Remaining follow-ups (carry-over + new)
- Everything still open from §7 (private GitLab-with-token path; forge ref
  pagination beyond 100; GitHub blame via GraphQL; per-user sharing).
- Manual a11y residual (§8.4): keyboard-only walkthrough + screen-reader smoke.
- The App-Store steps gated in §8.7.
