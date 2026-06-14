# LANTERN — Project Bible

*A read-only git repository browser for Nextcloud.*

Version of this document: **0.5** · Covers app version **2.2.2** · Revised across four review rounds plus the five-horizon 2.0 build, all live-verified on NC 34 (see §0.1 and CHANGELOG).

## 0.1 What changed across review rounds

**Round 1 (→ doc 0.2 / app 1.0.1).** Folded in an external review of 1.0.0. Substantive corrections: the §9.6 environment-hardening claim was **inaccurate** and was rewritten (the real guarantee is command selection, not config scrubbing) with a hard RCE constraint added to the v3 diff plan; the admin-save path was **broken** (OCS endpoint via the wrong URL helper) and was replaced with a dedicated controller route; the test suite became **committed and reproducible**; the babel build config was fixed; `info.xml` was raised to NC 34; syntax highlighting was given a theme and bounded; the UI was made keyboard-accessible.

*A correction to the round-1 revision note:* it previously stated that two review findings (missing `.gitignore`, missing `.babelrc`) were wrong because the files "were present in the shipped tarball and the reviewer's checkout filtered dotfiles." The first half is what this build session verified — both dotfiles are present in the tarball it packaged (confirmed by extracting it and listing them). But the second half — attributing their absence on the reviewer's side to a dotfile-filtering checkout — was **speculation stated as fact, and the reviewer has refuted it**: they checked with `ls -la` and `find .`, which do list dotfiles, and the files were genuinely absent from the copy they were handed. The accurate statement is therefore: *present in the tarball this session packaged; absent in the copy the reviewer reviewed; cause of the discrepancy unknown.* Either a different/altered copy was reviewed, or something dropped them in transit — but it was not the reviewer's tooling. The underlying babel point was valid regardless and was fixed.

**Round 2 (→ doc 0.3 / app 1.0.2).** A follow-up review verified the round-1 fixes and found two more things, both addressed here: (1) **MEDIUM — test portability:** the fixtures created the repo with `git init` and then asserted on a `master` branch, which fails on any environment where `init.defaultBranch` is `main` (common on modern CI/Homebrew). Fixtures now pin `master` portably via `git symbolic-ref HEAD refs/heads/master`, verified by running the suite under a simulated default-`main` git. (2) Re-running the suite repeatedly also exposed a **flaky binary-detection assertion**: the binary fixture used random bytes, which contain a NUL only ~69% of the time at that length, so "binary detected" failed intermittently. The fixture now uses deterministic content with guaranteed NUL bytes; the suite is green across 10 consecutive runs. Also: the highlight.js theme follows the OS color scheme (NC tokens + a `prefers-color-scheme` palette) rather than being hardcoded dark — note this tracks the *OS* scheme, which can diverge from NC's per-user theme setting (caveat recorded in the code; full NC-theme-aware palette is a v1.x item), and the **git binary path is now actually configurable** (admin field → validated absolute executable → read in the `GitBinary` registration), partially closing §14 item 7. Full list in CHANGELOG.md.

**Round 4 (→ doc 0.4 / app 1.0.4) — first live NC 34 install.** The app was installed on a real Nextcloud 34 container (PHP 8.4, git 2.47) and exercised. This cleared most of the §14 ledger live and found two bugs that lint, unit tests, and three static review rounds all missed because they only exist at the build-plus-runtime boundary:

1. **BLOCKER — the entire frontend was dead.** The webpack entries were named `lantern-main`/`lantern-admin`, but `@nextcloud/webpack-vue-config` sets `filename: ${appName}-[name].js` (appName = `lantern`), producing `js/lantern-lantern-main.js`. `Util::addScript('lantern','lantern-main')` requests `js/lantern-main.js`, which did not exist — and Nextcloud *silently omits* a script whose file is missing, so the page rendered an empty `#lantern` div with no JS and no error. (CSS worked only because `addStyle('lantern','main')` happened to match `css/main.css`.) **Fixed** by renaming the entries to `main`/`admin` so the prefixed output is `lantern-main.js`/`lantern-admin.js`. Confirmed against the config's source (`filename: ${appName}-[name].js`), not just reasoned.
2. **HIGH — cross-owned repos were silently unbrowsable.** Disabling system+global git config (correct, §9.6) also disables the only global fix for git's `safe.directory` "dubious ownership" guard. A repo owned by a different user than the web-server user — the *normal* server-side case — failed, masquerading as an empty repo. **Fixed** two ways: every invocation now passes `-c safe.directory=<repoPath>` (registry-realpath'd, trusted, never request input), and `isEmpty()` now probes `rev-parse --git-dir` first so an unreadable repo throws a real diagnostic instead of being reported as empty.
3. **MEDIUM (ops) — git is absent from the stock Nextcloud Docker image.** **Added** a `GitAvailable` setup check that warns admins when git can't be run, and the install docs now call this out.

Live run *confirmed working*: `app:enable` exits 0 (DI bootstrap, `info.xml` NC 34 compat, settings registration); admin page renders and CSS loads; the provider chain works end-to-end as the web-server user (default ref, dirs-first tree, blob read, commits newest-first); all six security cases blocked live; the 27-assertion suite passes in-container; the frontend build compiles; CSRF enforced on the read API.

*A note on review-finding accuracy:* three findings across rounds referenced things not actually in the artifact — the round-1 missing dotfiles, the round-3 "duplicate docs," and a round-4 claim that this document recommended the (removed-in-NC-34) `occ app:check-code` command. This bible has only ever referenced `occ app:enable`. These appear to stem from a flattened export and the reviewer's own verification commands; recorded for accuracy, with the substantive findings in every round being real and valuable.

---

## 0. How to read this document

This is the living reference for the Lantern app: what it is, why it's built the way it is, and where it's going. It is deliberately opinionated about architecture so that future work (especially the remote-forge provider) lands without rework. Sections 1–3 are orientation; 4–11 are the design of record; 12–13 are operational; 14 onward are the honesty layer — what's proven, what's assumed, and what must be checked against a live Nextcloud before trusting it in production.

A standing convention: anywhere this document says **[VERIFY]**, it marks a Nextcloud API surface that was written from knowledge that can drift between server releases and has *not* been run against a live NC 34 instance in this build. Those are collected in §14 so they can be cleared in one pass.

---

## 1. Identity

| Field | Value |
| --- | --- |
| Codename | LANTERN |
| App id | `lantern` |
| PHP namespace | `OCA\Lantern` |
| Display name | Lantern |
| License | AGPL-3.0-or-later (matches Nextcloud) |
| Target server | Nextcloud 30–34 (`info.xml` min 30, max 34) |
| Language stack | PHP 8.1+ backend, Vue 3 frontend |
| Current release | v2.2.2 — local + Files + GitHub + GitLab; commit-range diffs + cross-repo search; UI/IA cleanup + full-width fill |

The name ties to the idea of *illuminating* a repository you already hold — you're not fetching anything from outside, you're shining a light on what's on your own disk.

---

## 2. Vision and non-goals

### The problem

Nextcloud's official `integration_github` / `integration_gitlab` apps are *forge notification* integrations: they surface notifications, todos, and unified-search hits for repos, issues, and merge requests on a remote forge. What they do **not** do is let you open a repository and read it — browse its tree, read a file with syntax highlighting, or look at its history — inside Nextcloud. There has also never been a native way to browse git repositories that live *on the Nextcloud server itself*. Lantern fills that second gap first.

### Why in Nextcloud at all (vs. just running Gitea/Forgejo)

Running a dedicated forge beside Nextcloud is the standard answer and remains valid. Lantern's reason to exist is single-pane-of-glass for people who want it: one login, repos sitting next to the documents and data they relate to, your own infrastructure end-to-end, and one fewer service to harden and back up. For a privacy-first, self-hosted operator the appeal is keeping version-controlled material (scripts, notes, proofs of concept) *inside* the cloud they already trust rather than on an external forge.

### Product principles

1. **Read-only, always.** Lantern never mutates a repository. No commits, no pushes, no writes of any kind. This is a viewer. It massively shrinks the threat surface and the support burden.
2. **One UI, many backends.** The frontend and controllers talk only to a provider interface. Local-disk repos are v1; a remote forge is a later provider behind the same seam.
3. **Do one thing well.** A vocal part of the Nextcloud community explicitly wants stability over feature sprawl. Lantern stays narrow on purpose.
4. **Hostile-input posture by default.** Every ref and path is treated as attacker-controlled, even though v1 only exposes repos an admin deliberately configured.

### Explicit non-goals (v1)

- Writing, committing, pushing, or any repository mutation.
- Cloning or fetching remote repositories.
- Diffs, blame, branch/tag switching in the UI (these are roadmap, not v1).
- Per-user repository ACLs beyond "is this user allowed to use the app." v1 treats configured repos as visible to all app users; granular sharing is roadmap.
- Issue/PR/MR management (that's the existing integration apps' domain).

---

## 3. Scope ladder

| Version | Scope |
| --- | --- |
| **v1.0 (this build)** | Local on-disk repos. List repos → browse tree → read blob (syntax-highlighted, binary-safe) → list recent commits (optionally path-scoped). Read-only. Admin configures repos as JSON. |
| v1.x | Branch/tag picker in UI; per-repo group restrictions; raw blob download endpoint for binary/large files; basic in-repo path search. |
| **v2** | `RemoteForgeProvider` — browse GitHub/GitLab repos through their API behind the *same* `IRepoProvider` interface. No frontend rewrite. |
| v3 | Diff view (commit-to-commit and blob history); blame. The expensive UI work, deferred until the bones are proven. |

---

## 4. Architecture

### 4.1 The one decision that shapes everything: the provider seam

The two repository sources Lantern will eventually support — local disk and remote forge — share **almost no backend code** (one is git plumbing on local files; the other is an authenticated REST/GraphQL client). But they share **almost all frontend code** (a tree browser, a file viewer, a commit list don't care where the data came from).

So the split is drawn in exactly one place: the `IRepoProvider` interface. Backends are separate implementations of it; everything above it — controllers, routing, the entire Vue app — is written once. This is why Lantern is **one app, not two**. Going fully separate would mean building and maintaining the tedious UI twice.

```
            ┌─────────────────────────────────────────┐
   Browser  │  Vue 3 SPA (App + Repo/Tree/Blob/Commit) │
            └───────────────────┬─────────────────────┘
                                │ JSON over /apps/lantern/api/*
            ┌───────────────────▼─────────────────────┐
   PHP      │  RepoController  (thin; validates, maps) │
            └───────────────────┬─────────────────────┘
                                │
                ┌───────────────▼───────────────┐
                │  RepoProviderManager (dispatch)│
                └───────────────┬───────────────┘
                                │  IRepoProvider  ◄── the seam
              ┌─────────────────┴──────────────────┐
              ▼                                     ▼
   ┌────────────────────┐              ┌──────────────────────────┐
   │ LocalGitProvider   │   (v1)       │ RemoteForgeProvider (v2)  │
   │  → GitBinary       │              │  → forge API client       │
   │  → RefValidator    │              │                            │
   └────────────────────┘              └──────────────────────────┘
```

`RepoRegistry` sits beside the manager and answers "which repos exist and where," reading admin config and refusing anything that isn't a real git repo (and, optionally, anything outside an allowlisted base directory).

### 4.2 Layering rules

- **Controllers** never touch git or the filesystem directly. They resolve a repo via the registry, get a provider from the manager, call the interface, and translate exceptions to HTTP statuses. They stay thin so the logic that matters lives where it can be tested.
- **Providers** own all validation and all data access for their backend. A provider receives untrusted `$ref`/`$path` and is responsible for making them safe.
- **The git core** (`GitBinary`, `RefValidator`, `LocalGitProvider`, the `Model\*` value objects, the `Exception\*` types) has **zero dependency on the Nextcloud framework**. That is deliberate: it's the high-value, security-critical code, and being framework-free means it can be unit-tested directly against a real repository with no server install. (It was — see §13.)

---

## 5. Component map

```
lantern/
├── appinfo/
│   ├── info.xml                 App manifest: id, version, NC compat, nav, settings
│   └── routes.php               Maps URLs → controller methods
├── lib/
│   ├── AppInfo/Application.php   Bootstrap + DI wiring (registers providers here)
│   ├── Controller/
│   │   ├── PageController.php    Serves the SPA shell + sets CSP
│   │   ├── RepoController.php    JSON API: repos, tree, blob, commits
│   │   └── SettingsController.php  Admin-only save route (validates repo JSON)
│   ├── Service/
│   │   └── RepoRegistry.php      Which repos exist; validates they're real git repos
│   ├── Provider/
│   │   ├── IRepoProvider.php     ◄── THE SEAM. The contract every backend implements
│   │   ├── RepoProviderManager.php  Dispatches a repo to its provider by key
│   │   └── Local/
│   │       ├── GitBinary.php     Shell-free git executor (proc_open array args)
│   │       ├── GitResult.php     Immutable {stdout, stderr, exitCode, truncated}
│   │       ├── RefValidator.php  Allowlist validation for refs + paths
│   │       └── LocalGitProvider.php  Reads on-disk repos via GitBinary
│   ├── Model/
│   │   ├── RepoDescriptor.php    {id, name, path, provider} — path NOT serialized
│   │   ├── TreeEntry.php         One tree row {name, path, type, mode, oid, size}
│   │   ├── BlobContent.php       {path, size, binary, truncated, content}
│   │   └── CommitInfo.php        {hash, author, date, subject}
│   ├── Exception/
│   │   ├── RepoException.php             base
│   │   ├── RepoNotFoundException.php     → HTTP 404
│   │   └── InvalidRefException.php       → HTTP 400
│   ├── Settings/
│   │   ├── AdminSection.php      Settings → Administration → Lantern section
│   │   └── AdminSettings.php     The admin form (repo JSON + base + git path)
│   └── SetupCheck/
│       └── GitAvailable.php      Warns admins when git isn't runnable
├── templates/
│   ├── main.php                  <div id="lantern"></div> — Vue mounts here
│   └── admin.php                 Admin settings markup
├── src/                          Frontend source (built into js/ by webpack)
│   ├── main.js                   Mounts the Vue app
│   ├── admin.js                  Admin settings save logic
│   ├── api.js                    axios client for the JSON API
│   ├── App.vue                   Layout + state + view switching
│   └── components/
│       ├── RepoList.vue          Sidebar list of repositories
│       ├── TreeBrowser.vue       Breadcrumbed directory browser
│       ├── BlobViewer.vue        Syntax-highlighted file view (highlight.js)
│       └── CommitList.vue        Recent commit history
├── img/app.svg                   App icon (currentColor → themable)
├── css/main.css                  Uses NC design tokens (var(--color-*))
├── package.json / webpack.config.js / babel.config.js   Frontend build
├── Makefile                      build / lint / appstore packaging
├── tests/                        Committed test suite (no composer needed)
│   ├── run-core-tests.php        Self-contained runner (php tests/run-core-tests.php)
│   ├── fixtures.php              Builds deterministic fixture repos
│   ├── bootstrap.php             PSR-4 autoloader for CI
│   └── unit/LocalGitProviderTest.php   PHPUnit wrapper (same assertions)
├── LICENSE / CHANGELOG.md
└── docs/PROJECT_BIBLE.md         This document
```

---

## 6. The `IRepoProvider` contract

This is the most important interface in the codebase. Any new backend implements exactly this and nothing above it changes.

```php
interface IRepoProvider {
    public function getKey(): string;                  // "local", later "github"…
    public function defaultRef(RepoDescriptor $repo): string;
    public function listTree(RepoDescriptor $repo, string $ref, string $path): array;   // TreeEntry[]
    public function getBlob(RepoDescriptor $repo, string $ref, string $path): BlobContent;
    public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit): array; // CommitInfo[]
}
```

Contract obligations for an implementation:

- Treat `$ref` and `$path` as untrusted. Validate before use.
- `$path === ''` means the repository root.
- `listTree` returns entries sorted directories-first, then case-insensitively by name.
- `getBlob` must not return raw content for binary files (`content === null`, `binary === true`) and must cap inline content for large files (`truncated === true`, `content === null`).
- Throw `RepoNotFoundException` for missing repo/ref/path, `InvalidRefException` for validation failures.

Registering a second provider is a **one-line change** in `Application::register()` — add it to the array passed to `RepoProviderManager`, keyed by its `getKey()`.

---

## 7. Data model

All four models implement `JsonSerializable` and define their own wire shape, so the API never accidentally leaks server internals.

- **RepoDescriptor** `{id, name, path, provider}` — **`path` is intentionally omitted from `jsonSerialize()`.** Clients only ever see `{id, name, provider}`. The on-disk location never crosses the wire.
- **TreeEntry** `{name, path, type, mode, oid, size}` — `type` is `blob | tree | commit` (commit = submodule). `size` is `null` for trees.
- **BlobContent** `{path, size, binary, truncated, content}` — `content` is `null` whenever `binary` or `truncated` is true.
- **CommitInfo** `{hash, shortHash, authorName, authorEmail, date, subject}` — `date` is ISO-8601 (`%aI`).

---

## 8. HTTP API contract

All endpoints are GET, read-only, under `/apps/lantern/api`, and require an authenticated session (`#[NoAdminRequired]` = any logged-in user, not "no auth").

| Endpoint | Params | Returns |
| --- | --- | --- |
| `GET /api/repos` | — | `{repos: RepoDescriptor[]}` |
| `GET /api/repos/{repoId}/tree` | `ref?`, `path?` | `{ref, path, entries: TreeEntry[]}` |
| `GET /api/repos/{repoId}/blob` | `path`, `ref?` | `{ref, blob: BlobContent}` |
| `GET /api/repos/{repoId}/commits` | `ref?`, `path?`, `limit?` | `{ref, commits: CommitInfo[]}` |

When `ref` is omitted the provider resolves the repository's default branch. Errors return `{error: string}` with status 400 (invalid ref/path), 404 (not found), or 500 (unexpected) — internal error text is logged, never returned.

---

## 9. Security model

Read-only plus self-hosted does not mean low-stakes: the repos may hold sensitive material, and the app shells out to a binary. The threat model and the mitigations actually implemented in this build:

### 9.1 Command injection — *structurally eliminated*

`GitBinary` runs git via `proc_open()` with an **argument array**, not a command string. On POSIX systems an array command bypasses `/bin/sh` entirely, so there is no shell to inject into — a ref or path containing `;`, `$()`, backticks, or newlines is just an inert argument. This is the primary defense and it is structural, not a filter that can be bypassed.

### 9.2 Argument injection — *blocked by RefValidator*

Separately from the shell, a value like `--upload-pack=…` could be misread by git as an *option* rather than data. `RefValidator::assertRef()` rejects any ref starting with `-`, containing `..`, containing whitespace, or outside the allowlist `^[A-Za-z0-9._/\-]+(\^\{tree\})?$`. Commands that accept a pathspec also use the `--` separator so paths can never be parsed as options. *(Tested: `--upload-pack=evil`, `-x`, `a..b`, embedded newline, `../etc` are all rejected — §13.)*

### 9.3 Path traversal — *blocked by RefValidator*

`RefValidator::normalizePath()` strips leading slashes, rejects control characters, and throws on any `..` segment, so a request can never escape the repository tree. Additionally, git's own object model means `cat-file`/`ls-tree` operate on tree contents at a ref, not the live filesystem — but we don't rely on that alone. *(Tested: `../../../etc/passwd`, `..`, `src/../../escape` all rejected — §13.)*

### 9.4 Type confusion — *blocked by assertType*

Before listing a tree or reading a blob, `LocalGitProvider` runs `git cat-file -t <ref>:<path>` and confirms the object is the *expected* type. Asking for a directory as a file (or vice-versa) returns 404, not a misleading result. *(Tested — §13.)*

### 9.5 Resource exhaustion — *bounded*

`GitBinary` caps captured stdout (default 8 MiB), caps stderr (64 KiB), and enforces a 20-second wall-clock timeout that kills the process. `LocalGitProvider` refuses to inline blobs over 2 MiB (returns metadata with `truncated: true`). Commit limit is clamped to 1–200.

### 9.6 Config-driven code execution — *guarded by command selection, NOT by env scrubbing*

This subsection was **corrected after review**; the original wording overclaimed. Each git invocation does run with `GIT_CONFIG_NOSYSTEM=1` and `GIT_CONFIG_GLOBAL=/dev/null`, plus `GIT_TERMINAL_PROMPT=0`, `HOME=/nonexistent`, and `LC_ALL=C`. But those env vars disable **system and global** config only. Git *always* honors a repository's own `.git/config` — it has to, in order to read the repo at all — so that cannot be turned off.

The real reason Lantern is safe today is **command selection**, not the environment. The plumbing it runs — `cat-file`, `ls-tree`, `log --format`, `symbolic-ref`, `rev-parse` — does not act on the config directives that can execute programs (`core.fsmonitor`, `core.hooksPath`/hooks, aliases, `diff.external`, `*.textconv` filters, `.gitattributes` filters). The env scrubbing is belt-and-suspenders; the guarantee is that we never invoke a command that would honor those vectors.

**Sharp consequence for v3 (diffs).** `git diff` / `git log -p` *do* honor `diff.external`, `*.textconv`, and `.gitattributes` filters from the repository — all of which run external programs. Naively shelling out to produce diffs would reintroduce code-execution-by-malicious-repo. The v3 diff feature **must** generate diffs with those disabled (e.g. `-c diff.external= -c core.attributesFile=/dev/null` and equivalent, or use a plumbing/libgit2-style path that doesn't run filters). This is recorded as a hard constraint in §16.

### 9.6a Trust boundary of `.git/config`

Because repo-local config is always honored, a repository is only safe to expose if its `.git/config` (and hooks, attributes) is trusted. For **admin-configured server-side repos**, the admin owns those files — acceptable. **Do not point Lantern at a user-writable or sync-target repository** (e.g. one inside a user's Files) until a hardened reader is in place: there, `.git/config` is attacker-controlled. This directly constrains the "repos in Nextcloud Files" idea floated for a future provider (see §16/ADR-007).

### 9.7 Information disclosure

`RepoDescriptor::jsonSerialize()` omits the on-disk path. API errors return generic messages; details are logged server-side only. The admin can confine all configurable repo paths to an allowlisted base directory (`allowed_base`).

### 9.8 XSS in the blob viewer

File content is rendered with `highlight.js`, which HTML-escapes the source text and only emits its own `<span>` markup. Combined with the default strict Content-Security-Policy set in `PageController` (no inline scripts, no `eval`), a file whose contents are themselves HTML/JS cannot execute in the page.

### 9.9 Residual risks / open items

- Symlinks inside a work tree: git object reads operate on committed tree content, but `RepoRegistry`'s `realpath()` + base-dir check should be confirmed against symlinked repo roots. **[VERIFY]**
- v1 has no per-repo authorization beyond app access. Documented as a non-goal; do not expose repos containing secrets a given app user shouldn't see until per-repo group restrictions ship (v1.x).

---

## 10. Local git execution — design notes

- **Why `proc_open` with an array, not `exec()`/`shell_exec()`:** the latter two always route through a shell. The array form of `proc_open` is the only PHP primitive that runs a binary with arguments and *no shell*. That is the whole ballgame for injection safety.
- **Why the git binary, not a PHP git library:** v1 uses the system `git` for fidelity and zero composer dependencies in the security-critical core. A pure-PHP library (e.g. `czproject/git-php`) was considered; it still shells out under the hood in places, so it would not have removed the binary dependency while adding a supply-chain surface. The `GitBinary` seam means swapping implementations later is contained.
- **Commands used (all confirmed against a real repo — §13):**
  - default branch: `symbolic-ref --short -q HEAD`
  - object type: `cat-file -t <ref>:<path>` (`<ref>:` is the root tree)
  - tree listing: `ls-tree --long -z <ref> [-- <path>/]` (trailing slash = list contents; names come back repo-relative, so the leaf is derived locally)
  - blob size / content: `cat-file -s <ref>:<path>` then `cat-file -p <ref>:<path>`
  - history: `log --max-count=N -z --format=%H␟%an␟%ae␟%aI␟%s <ref> [-- <path>]` (␟ = `\x1f` unit separator; records split on NUL)

---

## 11. Frontend architecture

- **Vue 3**, mounted by `main.js` into `#lantern`. State lives in `App.vue`: the active repo, current `ref`/`path`, which view (`files`/`history`), and the selected blob.
- **`api.js`** is the only module that talks HTTP, via `@nextcloud/axios` (carries the session + CSRF token automatically) and `@nextcloud/router`'s `generateUrl`.
- **Components** are deliberately dumb: `RepoList`, `TreeBrowser` (breadcrumbs + dir navigation), `BlobViewer` (highlight.js), `CommitList`. Each fetches its own slice and emits navigation events upward.
- **Styling** uses Nextcloud CSS custom properties (`var(--color-*)`, `var(--font-face-monospace)`) so the app inherits the user's theme (light/dark/high-contrast) for free. This is intentional: a Nextcloud app should look native, not "designed," so the design discipline here is *conformance*, not a bespoke identity.

---

## 12. Build, install, configure, run

### Build the frontend

```bash
cd lantern
npm ci          # install pinned deps
npm run build   # webpack → js/lantern-main.js, js/lantern-admin.js
```

### Install into Nextcloud

Place the `lantern/` directory in your server's `apps/` (or a configured custom apps dir), then:

```bash
php occ app:enable lantern
```

### Configure repositories (admin)

Settings → Administration → Lantern. Provide a JSON array and (optionally) a base directory all paths must sit under:

```json
[
  { "id": "recon",  "name": "Recon scripts", "path": "/srv/git/recon" },
  { "id": "notes",  "name": "Engagement notes", "path": "/srv/git/notes" }
]
```

`allowed_base` example: `/srv/git`. The registry refuses any entry whose path isn't a real git repo (work tree with `.git`, or a bare repo with `HEAD` + `objects`) or that falls outside the base.

### Requirements on the server

- **`git` must be installed and runnable by the web-server user.** The stock
  `nextcloud` Docker image does NOT ship git — install it in the container (or
  set an absolute path in Lantern's admin settings if it's not on `PATH`). The
  `GitAvailable` setup check warns under Administration → Overview if git is
  missing.
- The web server user must have **read** access to each configured repository.
  Repos owned by a different user are handled: Lantern passes
  `-c safe.directory=<repoPath>` per invocation so git's dubious-ownership guard
  does not block read-only browsing.

---

## 13. Testing — what's proven and how

The framework-free git core is covered by a **committed, reproducible** test suite that needs only PHP + git (no composer, no Nextcloud):

```bash
php tests/run-core-tests.php      # self-contained runner, builds its own fixtures
```

It builds its own deterministic fixture repositories (a multi-commit repo with nested directories, a binary file, a second branch and a tag, plus an empty/unborn-HEAD repo), runs **49 assertions, all passing**, and cleans up. A PHPUnit wrapper (`tests/unit/LocalGitProviderTest.php` + `phpunit.xml` + `tests/bootstrap.php`) mirrors the provider assertions for CI once `composer require --dev phpunit/phpunit` is installed.

Coverage:

- *Functional:* default-branch resolution; root + subdirectory tree listing; directories-first sorting; correct leaf-name derivation; text blob read; binary blob detection (content suppressed); full and path-scoped commit history; fully-qualified entry paths.
- *Edge:* empty/unborn-HEAD repo lists as empty rather than erroring (regression test for the v1.0.1 fix).
- *Serialization:* `RepoDescriptor` JSON omits the on-disk path.
- *Security:* ref-injection rejection (`--upload-pack=evil`, `-x`, `a..b`, embedded newline, `../etc`); path-traversal rejection (`../../../etc/passwd`, `..`, `src/../../escape`); type-confusion rejection (blob-as-dir and dir-as-blob); missing-path → not-found.

### What is NOT yet proven

- ~~**`RepoRegistry`** is not yet unit-tested.~~ **RESOLVED (Horizon 1):** `RepoRegistry` (the `isGitRepo` / `realpath` / `allowed_base` containment logic, plus `validatePath`) is now covered by the core runner via stubbed `IAppConfig`/`LoggerInterface` (`tests/registry-stubs.php`). The `/srv/git-evil` false-prefix match — previously only "reviewed by inspection" — now has an explicit failing-if-broken assertion.
- The **Nextcloud-coupled layer** (controllers, DI bootstrap, settings, routing, templates) is written for NC 30–34 and **lints clean (`php -l`)**, but has not been run inside a live server. See §14.
- The **frontend build** has valid syntax (every `.js` and Vue `<script>` block passes `node --check`) but has not been run through the actual `@nextcloud/webpack-vue-config` toolchain.

---

## 14. Verification ledger — confirm against a live NC 34 before production

Each item is a Nextcloud API surface written from prior knowledge that should be checked against the running server. **Items 6 and the NC-version item were resolved in v1.0.1**; the rest remain open.

1. ~~App-config API.~~ **CLEARED (live NC 34):** `IAppConfig` get/set worked; settings registration loaded on `app:enable`.
2. **Security attributes.** `#[NoAdminRequired]`/`#[NoCSRFRequired]` resolved (read API returned 412 without a CSRF token, proving the route enforces CSRF). Still confirm the *admin-only* enforcement of the `SettingsController` save route through a real browser session. **[VERIFY — narrowed]**
3. ~~Settings interfaces.~~ **CLEARED (live):** the admin settings section/page rendered.
4. ~~Bootstrap DI.~~ **CLEARED (live):** `app:enable` exit 0; `Application::register` and the provider chain ran.
5. ~~`Util::addScript` bundle-name match.~~ **RESOLVED (v1.0.4):** the live install exposed a doubled-prefix mismatch (dead frontend); webpack entries renamed to `main`/`admin`. Re-confirm the page emits the script tag after a fresh build on the target server.
6. ~~Admin save route.~~ **RESOLVED (v1.0.1):** replaced the provisioning-API/OCS attempt with a dedicated admin-only `POST /apps/lantern/settings/save` controller route; `@nextcloud/axios` carries the CSRF token. Still confirm the CSRF/admin enforcement on the live server.
7. **Git binary discovery.** **Mostly resolved (v1.0.2):** an optional admin field sets an absolute git path (validated as an executable), read in the `GitBinary` registration; defaults to `git` on PATH. Still confirm the live read path on the target server. **[VERIFY]**
8. ~~Frontend build.~~ **CLEARED (live):** `npm install && npm run build` compiled cleanly (webpack 5, perf warnings only) and emitted the bundles.
9. **Symlinked repo roots** through `RepoRegistry::realpath()` + base-dir check. **[VERIFY]**
10. ~~NC version ceiling.~~ **RESOLVED (v1.0.1):** `info.xml` now allows up to NC 34 (released 2026-06-09) and declares PHP 8.1 minimum.

---

## 15. Architecture Decision Records

**ADR-001 — One app, pluggable providers (not two apps).** *Accepted.* Backends differ; UI doesn't. Splitting apps would duplicate the expensive UI. The split lives at `IRepoProvider`. *Consequence:* a git-plumbing bug can't touch the forge code and vice-versa, yet the UI is written once.

**ADR-002 — Build the local provider first.** *Accepted.* It's the genuinely missing capability and the one that fits a self-hosted operator; it also lets the risky UI iterate against the simplest possible, fully-deterministic data path (a local repo), with no OAuth or network flakiness. Remote forge follows once the UI is proven.

**ADR-003 — Read-only in v1 (and likely forever).** *Accepted.* Drops the threat surface enormously and matches the "do one thing well" principle. Write operations are explicitly out of scope.

**ADR-004 — System git via shell-free `proc_open`, not a PHP git library.** *Accepted.* Fidelity + zero composer deps in the security core; the array form removes the shell entirely. Revisit only if a pure-PHP, fork-free reader becomes compelling. (§10)

**ADR-005 — Framework-free git core.** *Accepted.* The security-critical code depends only on PHP so it can be unit-tested with no server. Validated by the 49-assertion suite (§13).

**ADR-006 — Conform to Nextcloud's design system rather than a bespoke visual identity.** *Accepted.* A native-feeling app uses NC's CSS design tokens (and, ideally, its component library); a "distinctive design" would be a defect here, not a feature. *Honest status:* v1 styles with NC CSS custom properties (`var(--color-*)`, monospace token) and uses semantic, keyboard-operable native elements with focus states — so it inherits theming and meets a basic a11y floor — but it does **not** yet use `@nextcloud/vue` components (`NcAppContent`, `NcAppNavigation`, `NcButton`). Full component adoption is a v1.x item; until then "native" means *themed and accessible*, not *pixel-identical to first-party apps*.

**ADR-007 — Server-side local repos before user-Files repos.** *Accepted; user-Files now BUILT (Horizon 2).* The hardened reader the flag below was waiting on exists (GitBinary §9.6a hardening flags), so user-Files browsing shipped as a per-user repo source rather than a distinct provider class. v1 browses admin-configured server-side repos because they're the simplest, fully-trusted, deterministically-testable path. The most novel single-pane angle — browsing a `.git` repo inside a *user's* Nextcloud Files — is deferred deliberately, not by oversight, because it crosses a trust boundary: a user-writable `.git/config`/hooks/attributes is attacker-controlled (§9.6a). It becomes a *third* provider behind the same seam only once a hardened, filter-disabled reader exists. This was raised in review as the more user-facing differentiator and is recorded here so the choice is conscious.

---

## 16. Roadmap

**Framing (adopted 2026-06-14).** 1.0.6 was a proof-of-concept: it browses only
admin-configured server-side repos, with no onboarding and no remote/forge
support — so a new user (the author included) has no obvious way to point it at
anything, least of all GitHub. The goal from here is to raise the bar to
something Nextcloud users actively want. The work is organized into five
**horizons**, sequenced so the reading experience leads and each new backend
slots behind the existing `IRepoProvider` seam without a frontend rewrite. The
owner has committed to all five. Horizon ≈ version mapping is noted per section.

### Horizon 0 — Earn trust (≈ v1.x) — *the credibility fixes*

The cheap, high-impact gap 1.0.6 exposed: the app never tells users what it does.

- **Guided empty state / first-run.** No repos configured currently renders a
  blank `#lantern`. Replace with a walkthrough explaining what Lantern browses
  (server-side git repos) and an explicit statement of what is *not* supported
  yet (remote forges / GitHub) so expectations are set in-product.
- **Admin repo form, not raw JSON.** A real add-repo form with a "Test path"
  action that validates a path is a real git repo before saving (replaces the
  hand-written JSON array).
- **User-facing docs.** README with screenshots + quickstart on the GitHub repo
  and the App Store listing; clear "what it does / doesn't do (yet)".

### Horizon 1 — Make reading delightful (≈ v1.x) — *retention*

- **README rendering** at tree root (Markdown) — the biggest "feels real" win.
- **Branch/tag picker** (the `refName` watchers are already wired for it).
- **Line numbers + line-range permalinks** (`#L20-L42`), shareable.
- **In-repo file search** (path + content).
- **Image & binary preview** — render images; raw-download endpoint for the rest
  (also covers large/truncated blobs).
- **Bundle/native polish:** ~~lazy-load highlight.js languages (trims the 312 KiB
  main-bundle warning)~~ **DONE** — hljs core + per-language on-demand chunks and
  a lazy Markdown renderer cut the main bundle to ~196 KiB, clearing webpack's
  size warning. `@nextcloud/vue` component adoption (ADR-006) **deferred to the
  live-verification pass** (heavy dep that would re-cross the bundle threshold;
  visual conformance needs a running NC to witness).
- **Hygiene carried from §14/§13:** ~~stubbed-config `RepoRegistry` test~~ (done);
  configurable git path live-confirm; clear the remaining `[VERIFY]` ledger.

### Horizon 2 — The Nextcloud-native differentiator (≈ v2.x, ADR-007) — **DONE, live-verified**

The single most novel single-pane angle, flagged in review as the bigger
user-facing differentiator.

- **Browse a `.git` repo inside a *user's own* Nextcloud Files.** Implemented as
  a per-user repo source (`UserRepoStore` + `UserRepoController`,
  `/api/my/repos*`, `MyReposManager.vue`) that resolves a Files folder to a local
  path (confined to the user's own Files, non-local storage refused) and reuses
  the local git engine — merged into `/api/repos`. No separate provider class
  was needed: once resolved to a local path the read is identical, and the
  hardened binary makes it safe. Live-verified on NC 34.
- **Security gate (hard, §9.6a) — CLOSED:** `GitBinary` now passes
  `-c core.fsmonitor= -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null`
  on every call, neutralising the repo-config program-execution vectors, so an
  untrusted user-writable repo is safe to read. Path confinement uses
  `realpath` + prefix check against the user's Files dir (also addresses the
  §9.9/§14 symlinked-root concern for user repos).
- **Still open (v2.x polish):** an "Open in Lantern" action from the Files app
  context menu (native integration nicety, not required for the capability).

### Horizon 3 — Reach the forges users live on (≈ v2) — **GitHub DONE, live-verified**

The "point it at GitHub" capability users (and the author) expect.

- `Provider\Forge\GitHubProvider` implements `IRepoProvider` against the GitHub
  REST API; per-user repos + encrypted PAT in `ForgeRepoStore`; registered in
  `Application::register` (a genuinely one-line manager addition). **No frontend
  changes** beyond the add-a-repo affordance — the tree/blob/history/refs/search
  UI is identical. **Live-verified against real GitHub** (octocat/Hello-World:
  refs, tree, blob). This proves ADR-001 — a remote backend behind the seam with
  zero UI rewrite.
- **Pagination baked into the contract (done):** `listCommits` gained an
  `$offset` param before this second implementer landed, so it was not a later
  breaking change. JSON→model mapping lives in pure static methods with 12
  fixture unit assertions.
- **Still open:** GitLab provider (same pattern), OAuth (PAT works today),
  GitHub code-search line numbers (the search API is file-level), and exposing
  cursor pagination on `listTree` (full-dir listing is fine for typical repos).

### Horizon 4 — Feel native & sticky (≈ v3 + integration) — **DONE, live-verified**

- **Unified Search** — in-repo code matches in Nextcloud's global search,
  deep-linking to the file+line (`LanternSearchProvider`). ✓
- **Dashboard widget** — recent commits across visible repos
  (`RecentCommitsWidget`, `IAPIWidget`). ✓
- **Per-repo group restrictions** — admin sets allowed groups per repo;
  `RepoDescriptor::visibleTo()` enforced on listing AND direct access (404, not
  403). Unblocks the old v1 authorization non-goal. ✓ (filter logic unit-tested;
  admin-bypass live-verified)
- **Diffs + blame** — commit diffs (`getCommitDiff`, colour-coded in the UI) and
  per-line blame (`git blame --porcelain`). **Security constraint (§9.6) MET:**
  `GitBinary` now passes `-c diff.external=` (plus fsmonitor/hooks/attributes
  off) on every call, so no repo-controlled driver can execute. ✓
- **Deep links** — file/line state in the URL query (`?repo=&ref=&blob=#L..`),
  restored on load → shareable links (also what unified-search results use). ✓
- **Still open (follow-ups):** sharing *to other NC users* with permission
  checks beyond group visibility; commit-to-commit (range) diffs; GitLab.

---

## 17. Risk register

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| NC API drift breaks the coupled layer | Medium | Medium | §14 ledger; min/max version pin in `info.xml`; thin controllers keep blast radius small |
| Frontend build fails against current `@nextcloud/*` majors | Medium | Low | Pin versions; CI build step; fall back to plain components (already minimal) |
| Large/pathological repo degrades performance | Low | Medium | stdout cap, blob cap, timeout, commit clamp (§9.5); caching is a v1.x option |
| Admin misconfigures a path to sensitive data | Low | Medium | `allowed_base` confinement; repo-must-be-git check; read-only |
| Scope creep toward a full forge | Medium | Medium | This document; ADR-003; non-goals in §2 |
| Large directory / deep history (no pagination or caching) | Medium | Medium | stdout/blob/commit caps bound a single response, but a 10k-entry tree or huge history is still unpleasant; bake cursor pagination into `IRepoProvider` before v2 (§16) and add a short-TTL cache in v1.x |
| Multiple git forks per request | Low | Low | `assertType` + `ls-tree` (and `+size`/`+content` on blobs) fork git 2–3×; fine interactively, first thing to optimize if it bites — do not pre-optimize |

---

## 18. Glossary

- **Blob** — a file object in git.
- **Tree** — a directory object in git.
- **Ref** — a human-readable pointer to a commit (branch, tag, `HEAD`).
- **Bare repo** — a repository with no working tree (just the `.git` internals at top level); how server-side repos are usually stored.
- **The seam** — shorthand in this document for `IRepoProvider`, the single interface that isolates backend differences from the rest of the app.
- **[VERIFY]** — a Nextcloud API assumption not yet confirmed against a live NC 34 instance (collected in §14).
