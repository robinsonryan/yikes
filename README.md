# Yikes

> "Yikes, we need to fix that."

In-app dev/QC note capture for **any Laravel app** — Inertia SPA, Statamic site, Blade
monolith, anything that serves HTML. Yikes renders a floating action button on every page; a
dev or QC user clicks it, writes a note about the page they're on (bug, layout issue, idea,
refactor) — or **picks a specific element** on the page to comment on — optionally attaches
on-demand screenshots, and saves. The note, plus auto-captured page context (URL, route name,
title, dark/light mode, viewport, an optional app-state snapshot), is written to flat files
**committed to the repo**. Later, Claude Code processes the approved notes via the bundled
`process-yikes` skill and implements them.

No database, no external service, no host build integration: the queue is markdown files in
your repo, and the UI is a prebuilt, self-contained Vue island served straight from the
package (shadow-DOM-isolated, so it never fights your CSS and your CSS never touches it).

For design rationale and the full spec, see [docs/SPEC.md](docs/SPEC.md).

## Installation

```bash
composer require --dev robinsonryan/yikes
```

The service provider is auto-discovered. Enable it per environment — Yikes is **off by
default** and intended for dev/staging only:

```dotenv
YIKES_ENABLED=true
```

That's it. While enabled, a middleware appended to the `web` group injects the island's
bootstrap into every HTML response, the FAB appears on every page, and the notes index lives
at `/yikes`. On production (flag off) the routes 404 and nothing is injected — the surface
does not exist.

Optionally publish the config to customize the storage path, middleware, route prefix,
capture limits, or UI injection:

```bash
php artisan vendor:publish --tag=yikes-config   # → config/yikes.php
```

Key config: `enabled` (`YIKES_ENABLED`), `path` (default `base_path('.yikes')`, env
`YIKES_PATH`), `middleware` (default `['web']` — guest-reachable so QC testers can file notes
from auth/consumer pages; the surface only exists where `YIKES_ENABLED` is on),
`route_prefix` (default `yikes`), `capture_state`, `max_screenshot_kb`, `max_state_kb`, and
`ui.auto_inject` / `ui.dark_selector` (see below).

### Hub mode (push-on-capture)

The package is dual-mode. With no hub configured (the default) everything above applies —
flat-file store, local index UI. Point it at a yikes hub and captured notes are pushed to
the hub's ingest API instead of being triaged locally:

```dotenv
YIKES_HUB_URL=https://yikes.example.com
YIKES_HUB_TOKEN=<ingest token for this project>
YIKES_PROJECT=<project slug on the hub>
```

In hub mode a capture still writes to the local `.yikes/` store first (capture never fails
because the hub is down), then pushes synchronously with a ~3s timeout. Unpushed bundles are
retried on the next capture, by `php artisan yikes:flush`, and every ten minutes via the
scheduler if the host app runs one — no queue worker needed. Pushed-state lives in
`push/<note-id>.pushed` / `.conflict` marker files beside the store. The local index/triage
UI answers 404 (the hub owns triage); the FAB capture surface stays. See
`docs/hub-contract.md` for the wire contract and `docs/SPEC-HUB.md` for the design.

## The element picker

The crosshair button on the FAB starts pick mode: hovering highlights page elements, clicking
one attaches the note to it. The note's context then carries the element's CSS selector, tag,
and visible text, plus an automatic screenshot of just that element — ideal for "this heading
is wrong / this button is misaligned" copy-and-polish passes on content sites.

## Host integration (all optional)

The island is fully self-contained — but a host app can enrich what gets captured:

### Context providers

```js
// Anywhere in your app's JS. The island loads at the end of <body>, so use
// the pre-island queue; late registration via window.Yikes works too.
(window.YikesReady ||= []).push((yikes) => {
    yikes.registerContextProvider(() => ({
        page: currentPageComponentName,          // e.g. Inertia's page.component
        account: { id: "…", name: "…" },
        department: { id: "…", name: "…" },
    }));
    yikes.registerStateProvider(() => piniaLikeStateObject); // saved as state/<note-id>.json
});
```

Anything a context provider returns is merged over the auto-captured core context (URL, route
name, title, dark mode, viewport, user agent) and stored in the note's frontmatter.

### Dark mode

The island follows the host theme automatically: it checks `.dark`/`.app-dark`/
`data-theme="dark"` on `<html>` and falls back to `prefers-color-scheme`. If your app uses a
different convention, set `yikes.ui.dark_selector` (e.g. `'.theme-dark'`).

### Injection control

`ui.auto_inject` (default true) registers the injection middleware globally on the HTTP
kernel. Turn it off if you'd rather place the snippet yourself —
render `RobinsonRyan\Yikes\Support\YikesAssets::injectHtml($request)` before `</body>`.

## Storage format

Everything lives under `config('yikes.path')` (default `.yikes/` at the project root), and —
apart from pending screenshots — is **meant to be committed**:

```
.yikes/
  notes/<YYYYMMDD-HHMMSS>-<uuid-first-8>.md     # one note: YAML frontmatter + markdown body
  state/<note-id>.json                          # app-state snapshot (absent if empty/disabled)
  screenshots/<note-id>/<seq>-<timestamp>.png   # attached screenshots
  screenshots/pending/<user-id>/<uuid>.png      # snapped but not yet attached (gitignored)
  README.md                                     # explains the dir (written on first use)
```

Do **not** gitignore `.yikes/` — the committed notes are the queue. The package writes a
`.yikes/.gitignore` on first use that excludes only `screenshots/pending/`.

### Note frontmatter schema

```yaml
id: <uuid7>
title: <string|null>
type: bug|layout|idea|refactor
status: new|on-hold|approved|done|ignored
created_at: <ISO8601>
created_by: { name: <string>, email: <string> }
context:
  url: <full url incl. query>
  route: <laravel route name|null>
  page: <page/component name from a host provider, or manually entered|null>
  title: <document title|null>
  account: { id: <string>, name: <string> }|null
  department: { id: <string>, name: <string> }|null
  dark_mode: <bool>
  viewport: { width: <int>, height: <int> }
  user_agent: <string>
  element: { selector: <css selector>, tag: <string>, text: <string|null> }|null
state_file: <relative path|null>
screenshots: [<relative paths>]
resolution: { commit: <sha|null>, note: <string|null>, completed_at: <ISO8601|null> }|null
```

The body below the frontmatter is the user's note text (markdown).

## Status workflow

```
new ──► approved ──► done   (set by Claude, with the implementing commit sha in `resolution`)
  ├──► on-hold              (parked; revisit later)
  └──► ignored              (won't do; kept for the record)
```

- Every note starts as `new` (or `approved` via the dialog's Fast-track toggle).
- A human triages in the Yikes index page (`/yikes`): **approved** is the signal that Claude
  should implement it; `on-hold` and `ignored` are skipped.
- **`done` is set only by Claude** (via the skill), together with a `resolution` block linking
  the implementing commit. Notes are never deleted by tooling — deletion is a human action in
  the index UI.

## UAT checklists

The same package ships a guest-reachable checklist surface at `/testing` that walks a human
test team through step-by-step suites; a failed step spawns a yikes note carrying the
checklist context. Definitions are authored YAML in `resources/checklists/` (suite files +
`testers.yaml`), versioned with the app; results land under the yikes store. See
[docs/SPEC.md](docs/SPEC.md) for the schema.

## The `process-yikes` skill

The skill that teaches Claude Code how to work the queue ships with the package at
[`skills/process-yikes/SKILL.md`](skills/process-yikes/SKILL.md). Install it into the host
repo as a committed symlink:

```bash
mkdir -p .claude/skills
ln -s ../../vendor/robinsonryan/yikes/skills/process-yikes .claude/skills/process-yikes
```

(Adjust the `../..` depth to reach vendor/ from your `.claude/skills/` directory.)

If your `.gitignore` excludes `.claude/*`, re-include the symlink — order matters:

```gitignore
.claude/*
!.claude/skills/
.claude/skills/*
!.claude/skills/process-yikes
```

Verify with `git check-ignore` that your other on-disk skills stay ignored and the symlink is
tracked.

## Processing notes with Claude

Once notes are approved, ask Claude Code:

> process the yikes notes

The skill reads `.yikes/notes/*.md`, reports counts by status, lists `new` notes for triage,
then for each **approved** note: reads the captured context, state snapshot, and screenshots
(as images), locates the page or element named in the context, implements the change, runs the
project's quality gates, commits with a Conventional Commits message referencing the note id,
and flips the note to `done` with a `resolution` block. Approved notes touching the same page
are batched into one coherent change.

## Development (this package)

- PHP tests: `composer install && vendor/bin/pest` (Testbench; the repo has a DDEV project —
  `ddev start`, then `ddev exec vendor/bin/pest`).
- Island UI: `npm install && npm run build` (Vite → `dist/`, committed), `npm run test:run`
  (Vitest), `npm run typecheck`.
- The prebuilt `dist/` is committed on purpose: composer consumers never run npm.

## Non-goals (v1)

No production exposure, no comments or assignment, no database storage.
See [docs/SPEC.md](docs/SPEC.md).
