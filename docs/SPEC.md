# Yikes — in-app dev/QC note capture

> "Yikes, we need to fix that."

A local composer package (`packages/yikes/`) that renders a floating action button (FAB) in the
app UI. Clicking it opens a dialog where a dev/QC user writes a note about the page they're on
(bug, layout issue, idea, refactor). Saving persists the note **plus captured page context** to
flat files **committed to the repo**, so Claude Code can later be asked to process the approved
notes and implement them. Includes on-demand screenshots, a minimal notes index page for
status control, and a `process-yikes` skill.

Extractable to a standalone package later — keep app coupling explicit and documented.

## v2 — standalone island (extraction, 2026-07-15)

The package was extracted from NBSS to `robinsonryan/yikes` and made **host-agnostic**. The
product decisions below still stand; the *delivery* architecture changed:

- **No host frontend requirements.** The Inertia/Vue/Volt coupling is gone. The UI is a
  prebuilt Vue island (`dist/`, committed, built with the package's own Vite + Tailwind),
  served by `AssetsController` and injected into every HTML response by a global middleware
  (`InjectYikesAssets`) while enabled. All UI mounts inside shadow roots with the package
  stylesheet adopted — host CSS and island CSS never interact (`@property` rules are lifted
  to a document-level `<style>`, since they don't register inside shadow DOM).
- **Pages are Blade-shelled.** `/yikes` and `/testing/*` render `yikes::page` (an empty shell
  with `#yikes-app` + JSON props); the island mounts the matching page component. Mutation
  endpoints answer JSON; pages refetch their own route with `Accept: application/json`.
- **Rich context became a provider API.** Core capture (URL, route name, document title, dark
  mode, viewport, UA) works everywhere. SPA specifics (Inertia page component, account/
  department, a Pinia snapshot) are supplied by host-registered providers —
  `window.Yikes.registerContextProvider()` / `registerStateProvider()`, or the pre-island
  `window.YikesReady` queue. The v1 sections below describing `usePage()`/`getActivePinia()`
  capture and app-side Inertia wiring are **historical**.
- **New: element picker.** A FAB action lets the user pick a page element; the note context
  gains `element: { selector, tag, text }` and an automatic screenshot of that element.
- **Own primitives.** Dialog/buttons/inputs/toasts are package-owned components on a frozen
  copy of the Tailwind palette (semantic `surface`/`primary`/`danger`/… ramps) with a
  `.y-dark` dark variant that follows the host theme (`ui.dark_selector` config, or
  auto-detection: `.dark`/`.app-dark`/`data-theme="dark"`/`prefers-color-scheme`).

## Locked product decisions (from spec conversation, 2026-07-11)

- Context auto-captured: URL + query, Inertia page component, route name, auth user,
  account/department, light/dark mode, viewport, user agent, **Pinia state snapshot**.
- Screenshots: **on-demand button** (not auto) so a *progression* of images can be captured
  while interacting; multiple images attach to one note.
- Fields: optional title, type (`bug|layout|idea|refactor`), status (`new|on-hold|approved|done|ignored`).
- Status `done` is set by **Claude** (the skill), with a link to the implementing commit.
- Storage: flat files, **committed** (screenshots too). One markdown file per note,
  YAML frontmatter (context) + body (the user's note).
- Availability: dev/staging via `YIKES_ENABLED` env (default **false**); never on prod for now.
  Auth required (QC team are app users). Not `local`-only — QC uses shared dev envs.
- Index UI: minimal — list, filter by status, change status (the **approved** button is how the
  user controls what Claude works on), edit title/body, delete. No context editing — the captured
  context, state snapshot, and screenshots are the permanent record.
- Package name `robinsonryan/yikes`, mirrors `packages/invoicing/` shape.
- The `process-yikes` skill is a required component.

## Storage layout (config `yikes.path`, default `base_path('.yikes')`)

```
.yikes/
  notes/<YYYYMMDD-HHMMSS>-<uuid-LAST-8>.md      # frontmatter + markdown body
                                                # (last 8, not first 8: uuid7's first 8 hex chars are
                                                # time bits shared by every id in a ~65s window — with
                                                # the second-resolution timestamp that collides)
  state/<note-id>.json                          # Pinia snapshot (kept out of frontmatter)
  screenshots/<note-id>/<seq>-<timestamp>.png   # attached screenshots
  screenshots/pending/<user-id>/<uuid>.png      # snapped, not yet attached to a note
  README.md                                     # explains the dir for humans/Claude
```

`.yikes/` must NOT be gitignored. `screenshots/pending/` SHOULD be gitignored
(transient), via `.yikes/.gitignore` shipped by the package on first write.

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
  route: <ziggy route name|null>
  page: <inertia component name>
  account: { id: <string>, name: <string> }|null
  department: { id: <string>, name: <string> }|null
  dark_mode: <bool>
  viewport: { width: <int>, height: <int> }
  user_agent: <string>
state_file: <relative path|null>       # null when Pinia snapshot empty/disabled
screenshots: [<relative paths>]
resolution: { commit: <sha|null>, note: <string|null>, completed_at: <ISO8601|null> }|null
```
Body below the frontmatter = the user's note text (markdown). `resolution` is written by the
skill when it flips status to `done`.

## Backend (mirror `packages/invoicing/`)

- `packages/yikes/composer.json`: name `robinsonryan/yikes`, psr-4 `RobinsonRyan\Yikes\` → `src/`,
  require `php ^8.3` + `illuminate/support|http|routing ^12|^13`, require-dev pest ^4 + orchestra/testbench,
  `extra.laravel.providers: [RobinsonRyan\Yikes\YikesServiceProvider]`.
- Root composer.json: path repository `{name:"yikes", type:"path", url:"packages/yikes"}` +
  require `"robinsonryan/yikes": "@dev"`. Also hard-add provider to `bootstrap/providers.php`
  (invoicing precedent).
- Config at `src/Config/yikes.php`, merged as `yikes`, publishable:
  `enabled` (env `YIKES_ENABLED`, default false), `path` (env `YIKES_PATH`, default `base_path('.yikes')`),
  `middleware` (default `['web']` — guest-reachable, like the checklists), `route_prefix` (default `yikes`),
  `capture_state` (default true), `max_screenshot_kb` (default 4096), `max_state_kb` (default 256).
- Provider `boot()`: merge config (in `register()`); **always** loadRoutesFrom package `routes/web.php` —
  every route carries an `EnsureYikesEnabled` middleware that aborts 404 when `yikes.enabled` is false.
  (Deliberate deviation from the original boot-time route gating: middleware gating makes enable/disable
  a runtime config concern, testable with plain `config()` calls instead of app re-boots.)
  Publish config.
- `src/` shape (follow invoicing's Services/Data organization; no Eloquent, no migrations):
  - `Data/Note.php` — immutable DTO of the schema above (fromFile/toFileContents round-trip).
  - `Support/NoteRepository.php` — all filesystem I/O: create, all()/filtered, find, updateStatus,
    delete (note + state + screenshots), attachPendingScreenshots, storePendingScreenshot,
    listPending(user), deletePending. Uses `symfony/yaml` for frontmatter. Writes `.yikes/.gitignore`
    + `.yikes/README.md` on first init.
  - `Http/Controllers/` — `NotesController` (index → Inertia `yikes/Index`, store, updateStatus, destroy),
    `ScreenshotsController` (storePending, destroyPending, show — streams image from outside public/,
    with strict path sanitization: ids validated as uuid/filename via regex, no traversal).
  - `Http/Requests/` — FormRequests for store (body required, title/type optional-with-defaults,
    optional initial status limited to `new|approved` — the dialog's fast-track toggle, context
    array validated loosely, state string size-capped), content update (title/body only), and
    status update (enum rule).
- Routes (named, under prefix + middleware from config):
  `GET /yikes` `yikes.index` · `POST /yikes/notes` `yikes.notes.store` ·
  `PATCH /yikes/notes/{note}` `yikes.notes.update` (title/body edit) ·
  `PATCH /yikes/notes/{note}/status` `yikes.notes.status` · `DELETE /yikes/notes/{note}` `yikes.notes.destroy` ·
  `POST /yikes/screenshots` `yikes.screenshots.store` · `DELETE /yikes/screenshots/pending/{id}` `yikes.screenshots.destroyPending` ·
  `GET /yikes/screenshots/{note}/{file}` `yikes.screenshots.show` · `GET /yikes/screenshots/pending/{id}` `yikes.screenshots.showPending`.
- Inertia: controller returns `Inertia::render('yikes/Index', [...])` — the app's page resolver is
  extended to serve `yikes/*` pages from the package (see Frontend).
- App integration: add to `HandleInertiaRequests::share()`:
  `'yikes' => ['enabled' => (bool) config('yikes.enabled', false)]` (mirror the `billing.acceptJs` block).
- `.env.example`: add `YIKES_ENABLED=false` with a one-line comment. Worktree `.env`: set `true`.

### Backend tests
- Package Testbench suite (`packages/yikes/tests/`, copy invoicing's TestCase pattern):
  Note DTO round-trip, NoteRepository behaviors (create/list/filter/status/delete/pending attach),
  path-traversal rejection.
- App feature tests `tests/Feature/YikesTest.php` (real app + User factory, `config(['yikes.path' => <temp>])`):
  disabled → routes absent (404); enabled + guest → index reachable and store works (author 'Unknown'); enabled + auth → store creates
  file with expected frontmatter; status update; destroy; screenshot upload → pending; note store
  attaches pending; screenshot serving works and rejects traversal. Note: routes are always registered
  and gated by the `EnsureYikesEnabled` middleware, so "disabled → 404" is just
  `config(['yikes.enabled' => false])` in the test — no app re-boot needed. phpunit.xml pins
  `YIKES_ENABLED=false` (`<server force>`) so the dev `.env`'s `true` never leaks into the suite.

## Frontend (all yikes UI lives in the package)

- Location: `packages/yikes/resources/js/` — aliased as `@yikes` in `vite.config.js` **and**
  `tsconfig.json` (`"@yikes/*": ["./packages/yikes/resources/js/*"]`). TypeScript, strict.
- App `resources/js/app.ts` resolver extension:
  ```ts
  const yikesPages = import.meta.glob("../../packages/yikes/resources/js/pages/**/*.vue");
  // in resolve(name): if name.startsWith("yikes/") resolve from yikesPages, else existing glob
  ```
- `resources/views/app.blade.php` preloads each Inertia page's Vite chunk by source path —
  `yikes/*` components must map to `packages/yikes/resources/js/pages/<name>.vue` there or the
  page 500s with a ViteException (found in browser verification).
- Components (package):
  - `YikesFab.vue` — fixed bottom-right cluster (z-40, below the sidebar drawer overlay at z-50)
    on a translucent pill background that visually groups the buttons: a home button (visits the
    `/yikes` index), a camera button (snap screenshot on demand) with a small badge showing
    pending-screenshot count, and a note button (opens dialog). Renders only client-side
    conditions are met; the *mount site* already gates on the shared prop.
  - `YikesDialog.vue` — uses the globally-registered `Dialog` (`sm:max-w-lg` default is fine):
    type select (default `bug`), optional title input, required textarea, pending-screenshots
    thumbnail strip with per-image include checkboxes (default checked) + delete, a collapsed
    "context that will be saved" summary, a fast-track toggle (save straight to `approved`),
    Save/Cancel. Submits via Inertia `useForm` to
    `route('yikes.notes.store')`, `preserveScroll`, success toast (`useToast` from `@/composables/useToast`).
  - `pages/Index.vue` — wraps `@/layouts/AppLayout.vue`; pattern-match
    `resources/js/pages/account/departments/Index.vue`. Card list of notes (newest first):
    type tag, status Select (immediate PATCH), title/body preview, context line (page · route ·
    account · dark/light · viewport), screenshot thumbnails (open full via `yikes.screenshots.show`),
    link to the captured URL, edit dialog (title/body), delete with confirm dialog. Status filter
    (SelectButton or Select).
    Page title styling per brand (`font-display text-3xl tracking-wide` etc.). Dark-mode classes
    on everything (`dark:` companions), semantic tokens only.
  - `composables/useYikesContext.ts` — builds the context object: `window.location.href`,
    `route().current()` (try/catch), `usePage().component`, auth user + account + department from
    `usePage().props`, dark mode via `document.documentElement.classList.contains('app-dark')`,
    viewport, userAgent, and Pinia snapshot via `getActivePinia()?.state.value` →
    safe-JSON (handle circulars with try/catch), size-capped per config (truncate + marker).
- Screenshot capture: add ONE npm dependency — prefer `@zumer/snapdom` (research its README before
  wiring; fall back to `html-to-image` if it fights the setup). **Dynamic-import it on first snap**
  so it's code-split out of the main bundle. Hide the FAB during capture. POST the PNG
  (multipart blob) to `yikes.screenshots.store`; toast + badge increment on success.
- Mount: once at the Inertia app root (`resources/js/app.ts` `setup()`), rendered as a sibling
  of the Inertia `App` when `initialPage.props.yikes?.enabled` — so the FAB exists on EVERY page
  (auth, consumer flow, testing checklists, the yikes index itself) regardless of layout. No auth
  gate: the routes are guest-reachable (revised 2026-07-13; originally per-layout + auth-gated).
- Types: add `yikes?: { enabled: boolean }` to the shared-props TS type (find where `auth`/`account`
  shared props are typed and extend there).
- Frontend tests: Vitest for `useYikesContext` (mock page/pinia; assert shape, truncation) and for
  frontmatter-adjacent pure utils if any. Keep light.

## Skill

- Canonical file: `packages/yikes/skills/process-yikes/SKILL.md`.
- Committed symlink: `.claude/skills/process-yikes` → `../../packages/yikes/skills/process-yikes`.
- `.gitignore` additions (order matters — re-include dir, re-exclude contents, re-include ours):
  ```
  !.claude/skills/
  .claude/skills/*
  !.claude/skills/process-yikes
  ```
  Verify with `git check-ignore` that other on-disk skills (pest-testing etc.) stay ignored.
- Skill behavior (frontmatter: name `process-yikes`, description triggers on "process the yikes
  notes", "work the yikes queue", etc.):
  1. Read `.yikes/notes/*.md`; group by status. Report counts.
  2. Work ONLY `approved` notes (unless the user names a specific note). `new` → list for triage,
     `on-hold`/`ignored`/`done` → skip.
  3. Per note: read context + state file + screenshots (they are images — view them), locate the
     page component via the `page`/`route` fields, implement the fix/feature, run the project's
     relevant quality gates, commit (Conventional Commits, reference the note id).
  4. Flip status to `done` by editing frontmatter: set `status: done` and
     `resolution: { commit: <sha>, note: <one-line summary>, completed_at: <ISO> }`, include that
     edit in the same commit or an immediate follow-up.
  5. Never delete notes; never touch screenshots.

## Quality gates (all inside the worktree, via `ddev exec --dir /var/www/html/.claude/worktrees/yikes`)

- Backend: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan` (larastan L8 — check whether
  `phpstan.neon` scans `packages/`; follow the invoicing precedent), package pest suite, app pest suite
  (uses Testbench sqlite / pgsql_testing respectively).
- Frontend: `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm run test:run`, `npm run build:check`.
- Do NOT run `ddev quality`/`ddev pest` bare (they'd hit the main checkout).

## UAT checklists (added 2026-07-12)

A second surface in the same package: guided manual test checklists for the human test team,
at `/testing/{tester}` (config `yikes.checklists.route_prefix`). Guest-reachable by design
(config `yikes.checklists.middleware`, default `['web']`) — testers read their seeded login
credentials there *before* authenticating; the whole surface still 404s behind
`EnsureYikesEnabled`, and staging sits behind an access proxy.

- **Definitions** are authored YAML files versioned with the app at `yikes.checklists.path`
  (default `resource_path('checklists')`) — deliberately OUTSIDE `.yikes/` so a persistent
  staging volume never shadows freshly-deployed definitions. One file per suite
  (`slug/title/description/tests[]`, each test `slug/title/goal/steps[]`); `testers.yaml`
  declares the roster (`slug/name/credentials[]`).
- **Results** are per-tester JSON under the yikes store (`.yikes/checklists/<tester>/<suite>.json`),
  so the same volume + pull workflow that protects notes covers test progress. Statuses roll up
  step → test → suite as `passed | failed | in-progress | pending`.
- **A failed step spawns a yikes note** (type `bug`, status `new`) carrying the reason, the step
  text, and a `context.checklist` block (`suite/test/step/tester`); the note id is stored on the
  step result. This is the seam between the two surfaces — checklists are push (the system says
  what to verify), notes are pull (the tester noticed something).
- Hierarchy: `testing/Index` (tester picker) → `Tester` (credentials + suites) → `Suite` (tests)
  → `Test` (steps with pass/fail; fail requires a reason via dialog; per-test reset for re-runs
  after fixes).
- **Authored-text conveniences:** `{first}` tokens in descriptions/goals/steps are replaced
  server-side with the viewing tester's slug (so instructions name that tester's own seeded
  logins), and `[label](url)` markdown-style links render as new-tab anchors (same-site paths
  and http(s) only; everything else HTML-escaped — see `resources/js/utils/checklistText.ts`).
- Backend: `Data/{Checklist,ChecklistTest,Tester}`, `Support/{ChecklistRepository,ChecklistResultStore}`,
  `Enums/StepStatus`, `Http/Controllers/ChecklistsController`, routes named `yikes.testing.*`.

### Staging sync (`scripts/yikes-pull.sh`, app-side)

The repo is canonical; the server is an inbox. The pull script rsyncs the staging volume into
`.yikes/`: notes/state/screenshots merge ADD-ONLY (uuid7 filenames are globally unique; existing
local files are never touched), checklist results use newer-mtime-wins (the same per-tester file
legitimately changes remotely). This transport is the MVP stand-in for a future central
"yikes hub" — swapping rsync-over-ssh for an HTTPS pull must not change these semantics.

## Non-goals (v1)

- No prod exposure / public feature-request mode (explicitly deferred).
- No note editing UI, no comments, no assignment.
- No database storage.
- Checklists: no assignment/run-history/reporting — that scale is when a dedicated
  test-management tool wins.
