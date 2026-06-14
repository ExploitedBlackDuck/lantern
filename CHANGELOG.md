# Changelog

## 2.1.0 — GitLab provider (2026-06-14)

A third remote forge behind the existing `IRepoProvider` seam — no controller
or frontend rewrite, the architecture was built for exactly this.

- **Browse GitLab repositories**, including **self-hosted instances** (each repo
  carries its own instance base URL; defaults to gitlab.com). Projects are
  addressed by their full, possibly-nested `group/subgroup/project` path.
  Authentication is a personal access token (`PRIVATE-TOKEN`), stored encrypted
  exactly like the GitHub token; public projects need none.
- **Full read parity, and then some.** Tree, file view, history, branch/tag
  picker, commit diffs, and code search all work. Because GitLab's REST API
  exposes them, GitLab additionally gets **blame** and **line-numbered search**
  results — parity the GitHub REST backend can't reach without GraphQL.
- **Same honest error contract as GitHub** (added in 2.0.1): rate-limit (429 /
  `RateLimit-*` headers) → an actionable "try again later" message; an invalid/
  expired/under-scoped token → a clear auth error; generic upstream errors map
  cleanly — never a phantom "Not found." `GitLabProvider::classifyStatus()` and
  the JSON→model mappers are pure and unit-tested.
- **Forge storage generalised.** `ForgeRepoStore` now records a forge `kind`,
  instance `host`, and `slug` per repo, with backward-compatible reading of the
  pre-2.1 GitHub rows (no migration needed). The add-repo UI gained a forge
  picker (GitHub / GitLab).
- **Test suite 113 → 141 assertions:** GitLab mappers (tree/blob/commits/refs/
  search/diff-assembly/blame), the error contract, pagination, and
  malformed/empty responses.
- *Not yet live-verified against a real GitLab instance* — the pure mapping
  layer is fixture-tested; the thin HTTP plumbing mirrors the live-verified
  GitHub backend and is pending a live pass (no GitLab instance in this build).

## 2.0.1 — v2 hardening, part 1 (2026-06-14)

The "feature zero" gate from `ROADMAP.md` §0: make the v2 surface as verified as
v1 was, starting with the GitHub error contract and real-repo edge cases. No new
user-facing features — correctness and tests only.

- **Honest GitHub failure states.** A rate-limited response (HTTP 429, or 403
  with the quota exhausted / a `Retry-After` header) is no longer reported to the
  user as "Not found." It now surfaces as a distinct `RateLimitException` →
  **HTTP 429** with an actionable message that names the reset time. An invalid,
  expired, or under-scoped token (401/403) surfaces as `ForgeAuthException` →
  **HTTP 502** telling the user to fix the token. Generic upstream errors (5xx,
  transport failures) map to **502**, no longer masquerading as 404. Previously
  every non-2xx collapsed into `RepoNotFoundException`.
- **Testable error contract.** The status→exception decision moved into the pure
  static `GitHubProvider::classifyStatus()`, and pagination math into
  `pageFor()`, so both are unit-tested with fixtures instead of only live.
- **Expanded test suite: 73 → 113 assertions.** New coverage for the GitHub
  error contract (rate-limit/auth/not-found/generic, case-insensitive and empty
  headers, the reset-time message), pagination edges, malformed/empty API
  responses (mappers stay total), and real-repo edges on the local provider:
  detached HEAD (defaultRef fallback + reads), no-commit/unborn repos
  (defaultRef, empty refs, history-throws), and single-ref/minimal repos.
- **Trust-boundary regression test.** A new fixture weaponises a repo's own
  `.git/config` with a malicious `diff.external` driver and proves both that the
  vector is real (plain `git diff` runs it) and that the `GitBinary` hardening
  flags neutralise it — guarding the §4 standing rule for any future `git diff`
  call. (The current show-based diff is additionally safe by command selection,
  since `git show` ignores `diff.external` without `--ext-diff`; also asserted.)

## 2.0.0 — Horizons 0–4 (2026-06-14)

A major step up from the 1.0.6 proof-of-concept, delivered as five "horizons"
and **live-verified end-to-end on Nextcloud 34** (Docker, PHP 8.5, git 2.47):

- **H0 — onboarding & trust:** guided first-run, an admin repo form with
  "Test path", honest docs.
- **H1 — reading experience:** README rendering, branch/tag picker, line numbers
  + line-range permalinks, in-repo search, image preview + download, and a
  lazy-loaded bundle (the size warning is gone).
- **H2 — your own Files:** browse a git repo inside your Nextcloud Files, behind
  a hardened (config-exec-disabled) git reader.
- **H3 — GitHub:** browse remote GitHub repositories behind the *same*
  `IRepoProvider` seam, with encrypted tokens — no frontend rewrite.
- **H4 — native integration + diffs/blame:** commit diffs, blame, per-repo group
  restrictions, unified-search results, and a recent-commits dashboard widget.

The framework-free core test suite grew from 27 to **73 assertions**. See the
per-horizon sections below for detail.

### H4 — native integration + diffs/blame

#### Added
- **Commit diffs** — click a commit in History to expand its colour-coded
  unified diff. Generated with repo-controlled `diff.external` / textconv /
  attributes filters disabled (§9.6), so a malicious repo cannot achieve code
  execution. (`getCommitDiff` on the seam; GitHub uses the `.diff` media type.)
- **Blame** — a per-file "Blame" toggle showing the author of each line
  (`git blame --porcelain`; GitHub has no REST blame, so it degrades gracefully).
- **Per-repo group restrictions** — admins can limit a repo to specific groups
  (a field in the admin form); enforced on both listing and direct access (404,
  not 403, so a hidden repo isn't revealed). Admins bypass.
- **Unified search** — in-repo code matches appear in Nextcloud's global search,
  deep-linking to the file and line.
- **Dashboard widget** — "recent commits" across your visible repos.
- **Deep links** — the file/line you're viewing is reflected in the URL
  (`?repo=&ref=&path=&blob=#L20-L42`) and restored on load, so links are shareable.

#### Fixed (caught in browser verification)
- **Lazy-loaded chunks 404'd in a real install.** Webpack's `publicPath: 'auto'`
  resolved to `/apps/lantern/js/` (where NC routes the entry bundle), but the
  split chunks are only served from `/custom_apps/.../lantern/js/` — so the
  Markdown renderer and highlight.js language chunks failed to load. This broke
  README rendering ("Could not load README.") and silently dropped syntax
  highlighting to plain text. Fixed by pinning `__webpack_public_path__` via
  `@nextcloud/router`'s `generateFilePath`. **Now browser-verified** (headless
  Chrome): README render, line numbers, highlighting, blame, diffs, and search
  all work with no console errors or 404s. (HTTP/curl checks couldn't catch this
  — it only manifests when the browser executes the code-split imports.)

### Horizon 0 (onboarding & trust)

First slice of the five-horizon roadmap (PROJECT_BIBLE §16): make it obvious how
to use the app and set expectations honestly, instead of presenting a blank page.

### Added
- **Guided empty state / first-run page.** When no repositories are configured,
  Lantern now explains what it does, points admins straight at the settings (or
  tells non-admins to ask one), and states plainly that remote forges
  (GitHub/GitLab) are roadmap, not available yet. Replaces the bare "no
  repositories configured" line that gave new users no path forward.
  (`PageController` now passes `is_admin` + settings URL to the page;
  `EmptyState.vue`.)
- **Admin repo form with "Test path".** The admin page is now a real form
  (id / name / path rows, add/remove) instead of a hand-edited JSON blob. A
  per-row **Test path** button validates a path is a genuine git repository
  (and inside the allowed base, if set) before saving, via a new admin-only
  `POST /settings/validate-path` route. (`AdminApp.vue`,
  `RepoRegistry::validatePath()`.)

### Changed
- README rewritten around the new form-based configuration flow, with an honest
  "what it is / isn't" section and a roadmap that names the GitHub goal.

### Note
- Real screenshots still need to be captured from a live install for the README
  and the info.xml `<screenshot>` URL.

### Horizon 1 (reading experience)

Second roadmap horizon: make browsing genuinely pleasant.

### Added
- **Provider seam extended** (`IRepoProvider`) with three capabilities, all
  covered by the core test suite: `listRefs()` (branches + tags, default
  flagged), `getBlobRaw()` (verbatim bytes for download / image preview), and
  `search()` (fixed-string `git grep`, binary-skipping, line-numbered). New
  read-only routes: `GET /api/repos/{id}/refs`, `/raw`, `/search`. The raw
  endpoint is hardened (nosniff + strict CSP; only raster images served inline,
  SVG and everything else download as attachments).
- **README rendering.** The current directory's `README.*` now renders below the
  tree as sanitized Markdown (markdown-it with raw HTML disabled + DOMPurify),
  or as plain text for non-Markdown READMEs.
- **Branch/tag picker** in the sidebar (consumes `/refs`); switching ref resets
  to the repo root.
- **Line numbers + line-range permalinks** in the file viewer: click a line
  number (shift-click for a range) to set `#L20-L42`, which highlights and
  scrolls; a "Copy link to lines" action copies the URL.
- **In-repo search** box (consumes `/search`); results list jumps to the matched
  file and line.
- **Image preview + download.** Raster images render inline via `/raw`; every
  file (and binary/oversized blobs) has a Download action.
- **`RepoRegistry` unit test** (the long-standing untested component): stubbed
  `IAppConfig`/`LoggerInterface` (`tests/registry-stubs.php`) exercise
  `isGitRepo` / `realpath` / `allowed_base` containment — including the
  `/srv/git-evil` false-prefix case — and `validatePath`.

### Changed
- Core test suite grew from 27 to **49 assertions** (refs/raw/search + registry).
- **Bundle slimmed: highlight.js languages are now lazy-loaded** (core + one
  on-demand chunk per language) and the Markdown renderer loads on demand too.
  The main bundle dropped from ~480 KiB (with Markdown) to **196 KiB**,
  eliminating webpack's >244 KiB performance warning (the long-noted "312 KiB
  bundle warning").

### Deferred
- `@nextcloud/vue` component adoption (ADR-006) is intentionally deferred to the
  live-verification pass: it is a large dependency (would re-cross the bundle
  threshold) and a visual-conformance change best witnessed on a running NC.

### Verified
- **H0 + H1 live-verified on Nextcloud 34** (Docker): provider chain, all
  endpoints (tree/blob/refs/search/raw/commits), binary suppression, path
  omission, all security cases blocked (400), and the page serves
  `lantern-main.js` with `data-is-admin`.

### Horizon 2 (browse repos in your own Files)

### Added
- **Browse git repos inside your own Nextcloud Files.** Any user can add a
  folder from their Files that is a git repo and browse it with the same tree /
  blob / history / search UI. Managed from the sidebar ("Add a repo from your
  Files", with a Test check); stored per-user. Resolved via the Files API to a
  local path, confined to the user's own Files, non-local storage refused.
  (`UserRepoStore`, `UserRepoController`, `/api/my/repos*`, `MyReposManager.vue`;
  merged into `/api/repos`.)
- **Hardened git execution** (`GitBinary`): every invocation now also passes
  `-c core.fsmonitor= -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null`,
  neutralising the repo-config directives that can execute a program. This is
  the security gate (§9.6a) that makes browsing an *untrusted* user-writable
  repo safe, and hardens admin repos too.

### Verified
- **H2 live-verified on Nextcloud 34**: validate/add/list/remove of a Files
  repo, unified browse of a repo inside the admin user's Files (tree/blob/refs),
  path not leaked, and admin (root-owned) repos still read correctly under the
  new hardening flags.

### Horizon 3 (browse GitHub repositories)

### Added
- **Browse GitHub repositories** behind the *same* `IRepoProvider` seam — the
  controllers and the entire Vue frontend are unchanged. Add a repo with
  owner/name (+ an optional personal access token for private repos) from the
  sidebar; it appears alongside local and Files repos and browses with the same
  tree / blob / history / refs UI. (`Provider\Forge\GitHubProvider`,
  `ForgeRepoStore`, `ForgeRepoController`, `/api/forge/repos*`,
  `ForgeRepoManager.vue`.) Tokens are stored **encrypted** (NC `ICrypto`) and
  never returned to the client.
- **Offset pagination baked into the contract** (`listCommits(..., $offset)`),
  added now — before the second implementer — so it is not a future breaking
  change. History gets a "Load more" button; `/commits` returns `hasMore`.

### Verified
- **H3 live-verified on Nextcloud 34 against the real GitHub API**: added
  `octocat/Hello-World` and browsed its refs, tree, and README through the
  seam; pagination confirmed on a local repo; forge tokens never leaked; local
  and Files repos unaffected. The GitHub JSON→model mappers also have 12
  fixture-based unit assertions. Suite now **64 assertions**.

## 1.0.6 — Stage 1 release prep

### Fixed
- **CRITICAL: info.xml was truncated to 0 bytes in the v1.0.5 package** (a
  version-bump script used `open(f,'w').write(open(f).read()...)`, which
  truncates the file before the read runs). The v1.0.5 tarball would not have
  installed. info.xml is reconstructed, schema-validated against the official
  Nextcloud info.xsd, and now carries real metadata.

### Added
- Real metadata in info.xml: author (Paul Ammann), website, bugs, repository,
  and screenshot pointing at github.com/ExploitedBlackDuck/lantern.
- Full AGPL-3.0 license text as COPYING (LICENSE now references it).
- `make release` target: builds the frontend and packages runtime-only files
  (including built js/) into a drop-in-installable tarball.
- SIGNING.md: the App Store certificate + code-signing walkthrough.

### Note
- This source tarball still needs `npm run build` (or `make release`) to produce
  js/ before it will run on drop-in. A screenshot must be committed at
  docs/screenshot-files.png to satisfy the info.xml <screenshot> URL.

## 1.0.5 — UI readability (post first-live-UI)

First time the UI was witnessed mounting on a live NC 34 (Hetzner test via a
local M1 Docker instance). One cosmetic blocker surfaced:

### Fixed
- **Wallpaper bleed-through.** The app content area and sidebar had no
  background of their own, so Nextcloud's desktop wallpaper showed through and
  the muted theme-text (correct on a solid surface) was unreadable. Panels now
  use `var(--color-main-background)` / `var(--color-main-text)`, so the app sits
  on an opaque themed surface that follows light/dark automatically. The code
  block also gets a subtle `--color-background-dark` surface with a border.

### Notes
- Two identically-named repositories in the sidebar are expected when the admin
  JSON contains two entries (with distinct ids) sharing a display name — it is
  config, not a bug. Duplicate display names are allowed by design (the id is
  the uniqueness key); remove the extra entry in admin settings if unintended.

## 1.0.4 — first live NC 34 install (Stage 0)

Installed and exercised on a real Nextcloud 34 container (PHP 8.4, git 2.47).
Two bugs that only exist at the build+runtime boundary were found and fixed.

### Fixed
- **BLOCKER: the entire frontend was dead.** Webpack entries `lantern-main`/
  `lantern-admin` + the config's `${appName}-[name].js` produced
  `lantern-lantern-main.js`, while `Util::addScript('lantern','lantern-main')`
  requested `lantern-main.js`; NC silently omits a missing script, so the page
  was an empty `#lantern` div. Entries renamed to `main`/`admin`.
- **HIGH: cross-owned repos were silently unbrowsable.** A repo owned by a
  different user than the web-server user tripped git's `safe.directory`
  guard, which our disabled global config couldn't fix, and the failure looked
  like an empty repo. Every git call now passes `-c safe.directory=<repoPath>`
  (trusted, realpath'd), and `isEmpty()` probes `rev-parse --git-dir` first so
  an unreadable repo throws a diagnostic instead of reporting empty.

### Added
- `GitAvailable` setup check: warns admins when git can't be run (the stock
  Nextcloud Docker image ships without git). Install docs strengthened.

### Confirmed live (clears most of the [VERIFY] ledger)
- `app:enable` exit 0; DI bootstrap, info.xml NC 34 compat, settings load.
- Provider chain works end-to-end as the web-server user; all 6 security cases
  blocked live; 27/27 suite passes in-container; frontend build compiles;
  CSRF enforced on the read API.

## 1.0.3 — third review round (polish)

### Changed
- Syntax-highlight palette comment now states the prefers-color-scheme caveat
  precisely: the dark variant follows the OS color scheme, which can diverge
  from Nextcloud's per-user theme setting. A fully NC-theme-aware palette is a
  v1.x item. (Behavior unchanged; the "theme-adaptive" claim was overstated.)

### Notes
- The "duplicate documentation files" raised in review were not part of the
  app: the tarball ships a single canonical copy of each doc
  (docs/PROJECT_BIBLE.md, CHANGELOG.md). The LANTERN_* files were standalone
  reading copies in the delivery directory only, and are no longer produced.

## 1.0.2 — second review round

### Fixed
- **Flaky/non-portable tests.** Fixtures now pin the `master` branch via
  `git symbolic-ref` (the suite previously assumed `master` but `git init`
  honors `init.defaultBranch`, so it failed on default-`main` CI). The binary
  fixture is now deterministic with guaranteed NUL bytes (random content made
  the binary-detection assertion pass only ~69% of the time). Green across 10
  consecutive runs and under a simulated default-`main` git.

### Changed
- Syntax highlighting is now theme-adaptive (Nextcloud tokens + a
  `prefers-color-scheme` palette) instead of a hardcoded dark theme.
- The git binary path is now genuinely configurable: an admin field, validated
  as an absolute executable, read in the `GitBinary` registration (was
  hardcoded to `git` despite the "configurable" comment).

### Doc
- Corrected the round-1 revision note: the claim that the reviewer's missing
  dotfiles were due to a dotfile-filtering checkout was speculation and is
  withdrawn. Accurate record: present in the packaged tarball, absent in the
  reviewed copy, cause unknown.

## 1.0.1 — review-response hardening

Fixes from an external code review of 1.0.0.

### Fixed
- **Admin save was broken.** Replaced the provisioning-API/OCS save path (wrong
  URL helper + missing OCS header) with a dedicated, admin-only, CSRF-protected
  controller route (`POST /settings/save`) that validates the repo JSON
  server-side. The app is now actually configurable.
- **Silent errors.** 400/404 responses now log the underlying git detail
  server-side instead of discarding it.
- **Build/packaging.** Replaced the incorrect `.babelrc` (used the babel config
  as if it were a preset) with `babel.config.js` re-exporting
  `@nextcloud/babel-config`. Fixed the `make appstore` rsync that would have
  excluded the built `js/`.
- **Empty repositories** no longer surface a confusing "not found"; the root of
  an unborn-HEAD repo lists as empty.
- **`info.xml`** now allows Nextcloud 34 (released 2026-06-09) and declares a
  PHP 8.1 minimum.
- **Syntax highlighting** now ships a theme (was rendering colorless), caps
  highlighting at 256 KiB, and derives the language from the file extension
  instead of slow/unreliable auto-detection.

### Changed
- Tree/repo rows and breadcrumbs are now real, keyboard-operable `<button>`s
  with visible focus styles (were clickable `<div>`s / href-less `<a>`s).
- `BlobViewer`/`TreeBrowser` now watch `refName` (forward-compat for the v1.x
  branch picker).
- Admin UI carries an explicit warning that configured repos are readable by all
  Lantern users (no per-repo access control yet).
- `isGitRepo` now accepts linked worktrees / submodules (`.git` as a file).
- Dropped the confusing `HOME=<repoPath>` git env in favor of `/nonexistent`.

### Added
- Committed test suite: `tests/run-core-tests.php` (self-contained, no composer)
  and a PHPUnit wrapper (`tests/unit/`) — 27 assertions, all passing.
- `LICENSE`, `CHANGELOG.md`.

## 1.0.0 — initial scaffold
Local-repository, read-only browser behind the `IRepoProvider` seam.
