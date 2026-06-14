# Changelog

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
