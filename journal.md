# Lantern — Development Journal

**As of 2026-06-14 · App version 2.2.0**

A running record of the 1.0.6 → 2.2.0 build: what was done, how it was verified,
the local test environment, and what's left. For the durable design-of-record
see `docs/PROJECT_BIBLE.md`; for the full change history see `CHANGELOG.md`.

> **2.0.1 → 2.2.0 (post-2.0 work) is summarised in §7 at the bottom;** §§1–6
> describe the 2.0.0 build and remain accurate except where §7 supersedes them.

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
(see CLAUDE.md HARD RULE 0). Re-verified green in the browser.

---

## 4. Local test environment (Docker NC 34)

A throwaway Nextcloud 34 container is the live test/verify target.

- **URL:** http://localhost:8099
- **Admin:** `admin` / `Lantern-Verify-2026-xZ` *(reset 2.2.0; NC password
  policy rejects weak/compromised values, hence the strong string)*
- **Container:** `nc-lantern` · **app installed:** `lantern 2.2.0`
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

## 7. Post-2.0 work (2.0.1 → 2.2.0)

Driven by `ROADMAP.md`: close the v2 hardening gate (§0), then the highest
value-to-effort features (§1 Tier 1 GitLab, Tier 2 diffs/search). This working
directory **is** a git repo now (the §6 "not committed" note was stale); work
landed on two branches with open PRs:

- **PR #1** `harden-v2-github-error-contract` → `add-gitlab-provider` (base
  `main`): 2.0.1 hardening + 2.1.0 GitLab.
- **PR #2** `tier2-diffs-search` (stacked, base `add-gitlab-provider`): 2.2.0.

(`gh` isn't logged in here; pull the token from the git credential helper into
`GH_TOKEN` for `gh` commands — see the `lantern-git-push-pr` memory.)

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

### Verification (cumulative)
- **Offline suite: 73 → 144 assertions**, all green
  (`php tests/run-core-tests.php`) — GitHub + GitLab mappers, both error
  contracts, pagination, malformed/empty responses, real-repo edges, and the
  range-diff `diff.external` RCE block.
- **Live gitlab.com: 20/20** (`php tests/live-gitlab.php` — network-gated).
- **Browser (headless Chromium) on the live NC 34 container: 16/16.** Deployed
  2.2.0, created the Alpha/Beta/test repos above, and drove both new UIs
  end-to-end: cross-repo search returned hits from both repos and navigated to
  file+line; History compare rendered the range diff. **Zero console errors,
  zero page errors, zero `/apps/lantern` 4xx/5xx.** (HARD RULE 0 satisfied.)

### Remaining follow-ups
- With-token **private** GitLab project path (needs a real PAT; the NC
  `IClientService` wiring mirrors the live-verified GitHub backend).
- Forge **ref pagination beyond 100** branches/tags (affects both GitHub and
  GitLab pickers).
- GitHub blame (GraphQL), per-user sharing, App-Store submission, screenshots.
