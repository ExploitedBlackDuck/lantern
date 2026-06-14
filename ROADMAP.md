# Lantern — Roadmap & Gap Analysis

**Date: 2026-06-14 · current version 2.0.0**

This captures *thinking about what to build next* — the candidate features, the
gaps (including the unglamorous operational ones), and the reasoning for
sequencing. It is intentionally option-space, not a committed plan: direction
(who Lantern is for) is still the owner's call. For the design-of-record see
`docs/PROJECT_BIBLE.md`; for agent context see `CLAUDE.md`; for history see
`journal.md`.

---

## 0. The gate before any new feature: harden what v2 already shipped

v2.0.0 roughly quadrupled the surface (Files repos, GitHub, diffs, blame,
search, dashboard, deep links) in a single jump. The verification leaned on the
build agent's self-report plus one live pass. **The biggest risk to the project
is not a missing feature — it's that the surface grew faster than its tests.**

Before adding breadth, close this:

- Finish the `journal.md` §11 reconcile (four-file version sync to 2.0.0,
  re-validate `info.xml` against the schema, confirm the pushed tree matches the
  live container with one `make release` → redeploy → browser smoke-test).
- Expand the test suite to match the new surface, especially:
  - **GitHub provider:** rate-limit handling, token-refresh/expiry failure,
    malformed/empty API responses, pagination edge cases.
  - **User-Files trust boundary:** confirm the `GitBinary` hardening holds for
    every git operation v2 added (diffs were the first to need it; any new git
    invocation must be re-checked — this is a standing rule, not a one-time fix).
  - **Empty/edge repos:** detached HEAD, single-ref, no-commit, very large tree.

Treat "make v2 as verified as v1 was" as **feature zero.**

---

## 1. Feature candidates (in recommended order)

### Tier 1 — high value, low risk, architecture already supports it

- **GitLab provider.** Highest value-to-effort. The `IRepoProvider` seam +
  `GitHubProvider` make this "the same shape again against a different API." It
  roughly doubles the addressable audience (self-hosted GitLab is big in the
  privacy/self-hoster crowd Lantern targets). The architecture was built for it.
  *If only one feature ships next, this is it.*
- **Gitea / Forgejo provider.** Same pattern; even more aligned with the
  self-hosted audience. Slightly more niche than GitLab but trivial once the
  forge-provider pattern has two implementations.

### Tier 2 — finish features that already half-exist

- **Commit-range diffs** (commit-to-commit), not just single-commit. This is
  what makes the history view genuinely useful.
- **GitHub blame** (REST has none — needs GraphQL). Local blame already works;
  this closes the parity gap for the GitHub source.
- **Cross-repo search** — escalate the in-repo `git grep` to "find this string
  across all my configured repos." Plays directly to the single-pane pitch.

### Tier 3 — new capability, higher risk, do after the gate

- **Per-user repo sharing with real permission checks.** Turns Lantern from
  "I can see my repos" into "my team can see our repos." **Caution:** this is the
  feature most likely to introduce an *authorization* bug — the worst kind for a
  tool that reads source. Write the threat model first; only after feature-zero
  hardening. Group restrictions already exist as the coarse version.
- **Recently-viewed / starred repos** — small, cheap polish that makes the app
  feel lived-in. Low risk, do anytime.

---

## 2. Operational / "will hurt at scale" gaps

These aren't features users ask for, but they bite as usage grows:

- **No caching anywhere.** Every tree/blob/commit view re-forks git or re-hits
  the GitHub API. Fine for one user; a problem with big repos, deep history, or
  GitHub rate limits. A short-TTL cache is probably warranted before "more
  features."
- **GitHub rate-limit handling.** Unauthenticated GitHub is 60 req/hr — trivially
  exhausted. Confirm there's backoff and a clear "rate-limited, try later" state
  rather than an opaque failure. (Correctness gap, verify in the §0 hardening.)
- **Large repo / large tree performance.** Commit pagination exists; does a
  10k-entry directory degrade gracefully? Multiple git forks per request was
  flagged as the first scaling cost — measure before optimizing.

---

## 3. Correctness / completeness gaps (real-repo edge cases)

- **Git LFS** — an LFS pointer file rendered as its text content is a confusing
  bug. Either resolve pointers or visibly label them.
- **Submodules** — a `commit`-type tree entry; show it as a submodule link, not
  a broken folder.
- **Symlinks** inside a work tree — confirm they're read from committed tree
  content, not the live filesystem (object reads should be safe; verify).
- **git notes**, multiple remotes, annotated vs lightweight tags — minor, but
  the kind of thing that surprises power users.
- **Large/binary files beyond the inline cap** — images preview; what about a
  huge file, or binary-but-not-image? Confirm the raw-download path covers it.

---

## 4. Trust / safety — standing items, not one-time

- **User-Files trust boundary vigilance.** Repo-local `.git/config` is always
  honored by git; the `GitBinary` hardening
  (`core.fsmonitor=`/`hooksPath=/dev/null`/`attributesFile=/dev/null`/`diff.external=`)
  neutralizes the known code-execution vectors. **Every new git operation must
  be re-checked against this** — it's a review checklist item for any PR that
  adds a git invocation, forever.
- **Forge token handling.** Tokens are encrypted at rest (`ICrypto`); keep
  confirming no decrypted token reaches a client response or a log line. Add a
  grep for token literals to CI.
- **Audit logging.** For a team instance, "who browsed what" may matter; there's
  none today. Worth considering if the audience becomes multi-user.

---

## 5. Accessibility / internationalization

- **No translations (l10n).** The app calls `$l->t()` but ships no translation
  scaffold — a real gap for an App-Store app with a global audience. Add the
  l10n directory + extraction.
- **Accessibility is at a "basic floor."** Full `@nextcloud/vue` component
  adoption (deferred in v2 for bundle size) is the path to genuinely native a11y
  and look-and-feel. Revisit if/when the bundle budget allows, or if NC
  externalizes the component library.

---

## 6. Explicit non-goals (resist these)

- **Anything that writes** (branches, comments, even "star on GitHub").
  Read-only is the load-bearing security simplification of the whole design
  (ADR-003). Writing multiplies the threat model and raises real token-exposure
  and AGPL questions. Treat "read-only forever" as a feature.
- **Executing/building repo content** (running notebooks, previewing built
  sites). This is exactly where the §9.6 RCE constraints bite hardest, and it's
  a different product.

---

## 7. Sequencing recommendation

Compressed: **harden v2 (§0) → GitLab provider (§1 Tier 1) → finish diff/blame
gaps (§1 Tier 2).** That keeps the risk profile flat while roughly doubling
reach and completing the features users poke at first. Caching (§2) slots in
during or right after the hardening gate if large-repo pain shows up. Sharing
(§3 Tier) and l10n come once direction (audience) is decided.

**Open direction question (drives the ranking):** who is Lantern for as it
grows — just the owner's repos, a small team/family instance, or a public
App-Store audience? Breadth (more forges) favors public; collaboration
(sharing, audit) favors team; "solid and small" favors just hardening §0 and
stopping. This is recorded as undecided.
