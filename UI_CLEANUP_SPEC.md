# Lantern — UI Cleanup Spec (for Claude Code)

**Date: 2026-06-14 · against app v2.0.0 · target a v2.1.0 / UI-polish release**

## How to use this spec

This describes **intent and concrete guidance**, not verbatim edits. The author
of this spec could not see the live v2 component source — so **read the real
files first** and adapt. Confirm actual filenames, class names, and where
components are mounted before changing anything; where this spec names a file or
class, treat it as "the component responsible for X — verify."

Likely components involved (confirm in `src/`):
`App.vue` (layout), `RepoList.vue` (repo selector), `TreeBrowser.vue`
(breadcrumb + file tree), `RefPicker.vue` (branch/tag), `SearchBox.vue`
(per-repo search), `BlobViewer.vue`, plus `css/main.css`.

**Constraints that still hold (do not regress):**
- Nextcloud CSS design tokens only (`var(--color-*)`, `var(--border-radius)`,
  `var(--font-face-monospace)`); no hardcoded palette. (ADR-006.)
- No `@nextcloud/vue` components — keep semantic, keyboard-operable native
  elements with `:focus-visible` outlines. (ADR-006.)
- Keep the lazy-chunk `__webpack_public_path__` pinning (HARD RULE 0) and the
  `main`/`admin` entry names. Don't touch the build wiring.
- After changes, re-run the headless-browser verification (CLAUDE.md recipe):
  green = renders AND zero console errors AND zero 404s.

---

## The core problem (from screenshots)

**Everything is the same grey pill.** Repo selectors, the breadcrumb, and the
file-tree rows all share one visual language, so there's no distinction between
*navigation chrome* and *content being browsed*. The most visible symptom: the
repo name appears twice as identical pills — once selected in the Repositories
list, once again above the file tree as the breadcrumb root — so the second
reads as a stray duplicate button rather than "you are here."

**The target mental model:**
- **Sidebar = "which repo am I in"** — global search, the repo list, the add
  buttons. Nothing else.
- **Main pane = "browse this repo"** — a toolbar (Files/History + branch picker +
  in-repo search + breadcrumb), then the tree or the file view.

---

## Task 1 — Breadcrumb: stop rendering it as a pill

**Problem:** the path root (e.g. "Test Repo") above the tree is styled as a pill
identical to the repo selectors, duplicating the selection visually.

**Target:** a true path breadcrumb — muted text with `/` separators, each
ancestor a clickable link, the current segment plain (non-link). At the repo
root, render just the repo name as quiet breadcrumb text (or omit it entirely,
since the selected repo is already highlighted in the sidebar list). It must
**not** use the repo-pill styling.

**Markup intent** (adapt to the real breadcrumb in `TreeBrowser.vue`):
```html
<nav class="lantern-breadcrumb" aria-label="Path">
  <button type="button" class="lantern-crumb-link" @click="navigate('')">{{ repo.name }}</button>
  <template v-for="c in crumbs" :key="c.path">
    <span class="lantern-crumb-sep" aria-hidden="true">/</span>
    <button type="button" class="lantern-crumb-link" @click="navigate(c.path)">{{ c.label }}</button>
  </template>
  <!-- current segment (last) is plain text, not a link -->
</nav>
```

**CSS intent:**
```css
.lantern-breadcrumb {
  display: flex; flex-wrap: wrap; align-items: center; gap: 4px;
  margin: 4px 0 12px; font-size: 90%;
  color: var(--color-text-maxcontrast);
}
.lantern-crumb-sep { color: var(--color-text-maxcontrast); }
.lantern-crumb-link {
  background: none; border: none; padding: 2px 2px;
  font: inherit; color: var(--color-primary-element, var(--color-primary));
  cursor: pointer; border-radius: var(--border-radius);
}
.lantern-crumb-link:hover { text-decoration: underline; }
.lantern-crumb-link:focus-visible {
  outline: 2px solid var(--color-primary-element, var(--color-primary));
  outline-offset: 1px;
}
```
Remove any pill background/`.lantern-tree-row`-style class from the breadcrumb.

---

## Task 2 — File tree: render as rows, not pills

**Problem:** `src` / `data.bin` / `README.md` are grey pills, the same shape as
the repo buttons, so the content you're browsing looks like more navigation.

**Target:** a list of rows. No pill background at rest; a subtle full-width hover
highlight; icon + name on the left, size muted on the right. Keep them real
`<button>`s (keyboard-operable) but styled as rows, not pills. The active/open
file gets a left-accent or `--color-primary-element-light` background.

**CSS intent** (adjust the existing `.lantern-tree-row`):
```css
.lantern-tree { list-style: none; margin: 0; padding: 0; }
.lantern-tree-row {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 6px 8px;
  border: none; background: none;            /* no pill at rest */
  font: inherit; color: inherit; text-align: left;
  border-radius: var(--border-radius); cursor: pointer;
}
.lantern-tree-row:hover { background: var(--color-background-hover); }
.lantern-tree-row.is-open {                  /* the currently-viewed file */
  background: var(--color-primary-element-light, var(--color-background-dark));
}
.lantern-tree-row .name { flex: 1 1 auto; }
.lantern-tree-row .size { color: var(--color-text-maxcontrast); font-size: 90%; }
.lantern-tree-row:focus-visible {
  outline: 2px solid var(--color-primary-element, var(--color-primary));
  outline-offset: -2px;
}
```
Use a folder vs file icon (keep whatever icon approach is already there); the
point is the **row container** stops being a pill.

---

## Task 3 — Sidebar / main split: move per-repo controls into a main-pane toolbar

**Problem:** the sidebar stacks global search → repo list → add buttons →
branch/tag picker → per-repo search → breadcrumb → *then* the tree. The thing
the user came to see is pushed to the bottom, and there are two search boxes far
apart doing different things.

**Target layout:**

```
┌── sidebar (≈280px) ──┐ ┌─────────── main pane ───────────────────────┐
│ Search all repos     │ │ [Files] [History]   [branch ▾]  [find in repo]│  ← toolbar
│ Repositories         │ │ Test Repo / src                              │  ← breadcrumb
│  Alpha               │ │ ──────────────────────────────────────────── │
│  Beta                │ │ (tree rows, or the file view)                 │
│  Test Repo  (active) │ │                                               │
│ + Add from Files     │ │                                               │
│ + Add GitHub repo    │ │                                               │
└──────────────────────┘ └───────────────────────────────────────────────┘
```

**What moves:** relocate the `RefPicker` (branch/tag) and the per-repo
`SearchBox` component instances out of the sidebar template and into a toolbar
row in the main pane, on the same line as (or directly under) the Files/History
tabs. The **global** "Search all repositories" box stays in the sidebar. The
breadcrumb (Task 1) sits just under the toolbar, above the tree/file view.

**What stays in the sidebar:** global search, the repo list, the two add
buttons. Nothing else.

This is mostly moving existing component instances in `App.vue` (or wherever the
layout is composed) and adjusting the fl/grid containers — not rewriting the
components themselves. Keep each component's props/events intact; only their
*placement* changes.

**Toolbar CSS intent:**
```css
.lantern-toolbar {
  display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
  padding-bottom: 8px; border-bottom: 1px solid var(--color-border);
  margin-bottom: 8px;
}
.lantern-toolbar .lantern-tabs { margin-right: auto; }  /* tabs left, controls right */
```

---

## Task 4 — Selected-repo state in the sidebar

**Problem:** the active repo ("Test Repo") is only faintly distinguished.

**Target:** unmistakable active state, and correct semantics. Keep the repo
buttons keyboard-operable; mark the active one with `aria-current="true"` and a
clear visual (filled `--color-primary-element-light` and/or a left accent bar).
The repo selectors *can* keep a pill/chip look — that's fine for a selector — as
long as Tasks 1 and 2 have removed pills from the breadcrumb and the tree, so
pills now mean only "pick a repo."

```css
.lantern-repo-item[aria-current="true"],
.lantern-repo-item.is-active {
  background: var(--color-primary-element-light);
  font-weight: 600;
  box-shadow: inset 3px 0 0 var(--color-primary-element, var(--color-primary));
}
```

---

## Task 5 — Resolve the "GitHub or GitLab" label (scope honesty)

**Problem / decision:** the add button reads **"Add a GitHub or GitLab
repository,"** but the v2 dev journal (§6) lists GitLab as **not built**. One of
these is wrong. **Determine which by reading the code**, then fix accordingly:

- **If there is no working GitLab path** (no `GitLabProvider`, the add flow only
  handles GitHub): change the button label and any copy to **"Add a GitHub
  repository"** until GitLab actually ships. Do not advertise a capability that
  isn't there.
- **If GitLab *is* implemented** (a `GitLabProvider` behind `IRepoProvider`, and
  the add flow handles it): the label is correct — instead update the stale
  journal §6 and `CHANGELOG`/`PROJECT_BIBLE` to record GitLab as shipped.

Report which case is true; it changes whether this is a one-line label fix or a
docs-sync.

---

## Acceptance criteria

1. The repo name no longer appears as two identical pills; the breadcrumb is
   clearly path text, visually distinct from the repo selectors.
2. File-tree entries read as rows (hover highlight, no pill at rest); the open
   file is clearly marked.
3. Branch/tag picker and in-repo search live in the main-pane toolbar; only
   global search, the repo list, and add buttons remain in the sidebar; the
   tree/file view is the first substantial thing in the main pane.
4. The active repo is unmistakable and carries `aria-current`.
5. The add-button label matches reality (GitHub-only, or GitHub+GitLab if built).
6. All controls remain keyboard-operable with visible focus; theme tokens used
   throughout (verify in both light and dark NC themes).
7. **Browser verification passes** (headless recipe): content renders, zero
   console errors, zero 404s. Capture fresh screenshots for the README while
   you're in there (still an open follow-up).

## Out of scope (don't get pulled in)

Full `@nextcloud/vue` adoption (deferred, ADR-006); any new feature from
`ROADMAP.md`; anything that writes to a repo. This is presentation/IA only —
no provider, controller, or git-core changes.
