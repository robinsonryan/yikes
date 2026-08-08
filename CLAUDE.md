# Yikes

In-app dev/QC note capture for any Laravel app. A floating action button on every
page lets a dev, QC tester, or coding agent file a note about the page they're on —
with auto-captured context, on-demand screenshots, and an element picker — and the
note goes either to a flat-file queue in the repo or to a central triage hub. The
same package also ships a guest-reachable UAT checklist surface whose failed steps
spawn notes.

Composer name: `robinsonryan/yikes` — a **library**, not an application.

## Conventions

@import ./constitution.md
@import ./imports/package-conventions.md
@import ./imports/package-quality-gate.md
@import ./imports/testing-conventions.md
@import ./imports/php-conventions.md
@import ./imports/git-conventions.md
@import ./imports/frontend-conventions.md

> `frontend-conventions.md` **is** imported here — unlike most first-party packages,
> yikes has a real frontend half (Vue 3 + Vite + Tailwind 4 + Vitest). The other
> inherited app conventions that `link.sh` drops into `.claude/imports/`
> (`authorization-conventions.md`, `pwa-conventions.md`, `ddev-worktrees.md`) are
> deliberately **not** imported: they describe Inertia `can` maps, app-shaped Vite
> wiring, and nested app worktrees, none of which exist in a package. Read one if a
> question genuinely calls for it; do not load them by default.

> `.claude/` is a set of **harness symlinks** and is gitignored — a fresh clone has
> none of them and the `@import`s above resolve to nothing. If a convention file is
> missing, restore the link rather than guessing:
> `~/workspace/harness/link.sh project laravel-package $(pwd)`

## Architecture: a prebuilt, self-contained island

There is **no host build integration**, by design. The UI is a Vue island compiled
by this package's own Vite into `dist/`, served straight out of the package by
`AssetsController` (`GET /yikes/assets/{file}`), and injected into every HTML
response by the globally-pushed `InjectYikesAssets` middleware. Consumers install
with composer and never run npm.

Consequences worth internalizing before you change anything:

- **Everything mounts inside shadow roots.** `resources/js/standalone.ts` attaches a
  shadow root, adopts the package stylesheet (with an inline `<style>` fallback for
  browsers without constructable stylesheets — older iOS Safari and in-app WebViews,
  where the island otherwise renders completely unstyled), and lifts `@property`
  rules to a document-level `<style>` because they don't register inside shadow DOM.
  Host CSS and island CSS never interact.
- **The package owns its primitives.** `resources/js/ui/Y*.vue` — dialog, buttons,
  inputs, toasts — on a frozen copy of the Tailwind palette with a `.y-dark` variant
  that follows the host theme (`ui.dark_selector`, or auto-detection of
  `.dark` / `.app-dark` / `data-theme="dark"` / `prefers-color-scheme`).
- **Package pages are Blade shells.** `/yikes` and `/testing/*` render `yikes::page`
  (empty shell, `#yikes-app` with JSON props); the island mounts the matching page
  component from the `PAGES` map. Mutations answer JSON; pages refetch their own
  route with `Accept: application/json`. There is no Inertia here.
- **Rich context is a provider API, not coupling.** Core context (URL, route name,
  title, dark mode, viewport, UA) works everywhere. Hosts add page component,
  account, app-state via `window.Yikes.registerContextProvider()` /
  `registerStateProvider()` or the pre-island `window.YikesReady` queue. Provider
  payloads must be **flat** — `BoundedContext` rejects nesting with a 422.

**Editing `resources/js/**` or `resources/css/**` means rebuilding the bundle.** A
frontend change that isn't rebuilt ships stale, silently.

## `dist/` is committed — stage it with the source change

`dist/yikes.js` (entry) and `dist/yikes-<hash>.js` (the lazily-imported `@zumer/snapdom`
screenshot chunk) are **tracked in git**. That is the whole delivery mechanism; it is
not an accident and it is not gitignored.

- Rebuild with `ddev exec npm run build` and **stage `dist/` in the same commit as
  the frontend source**.
- `npm run quality` **ends in `vite build`**, so running the gate can leave `dist/`
  modified. If it does, that diff *is* your rebuild — commit it, don't discard it.
- The pre-commit hook runs `npm run quality` too, which rebuilds into the working
  tree. The hook never stages anything and reads the working tree rather than the
  index, so a rebuild it triggers lands **outside** the commit you just made. Build
  and stage first; don't rely on the hook.
- `emptyOutDir: true` plus a content-hashed chunk name means the snapdom chunk's
  filename changes when its content does — expect an add+delete pair in the diff,
  not a clean modify.
- The cache-buster (`YikesAssets::version()`) is derived from `dist/yikes.js`'s
  mtime and size, so consumers get a fresh URL automatically on upgrade.

## The gates — there are two

**PHP:** `ddev composer quality` — `lint:check` → `analyze` → `refactor:check` →
`test`. Verify-only. Fix with `ddev composer lint` / `ddev composer refactor` and
re-stage. PHPStan is level 8 with `phpVersion: 80200` pinned to the declared floor —
the dev container is PHP 8.4, so without the pin 8.3-only syntax analyses clean here
and fatals in a consumer.

**Frontend:** `ddev exec npm run quality` — `lint:check` → `typecheck` → `test:run`
→ **`build`**. The build step is part of the gate *in this package* (it was removed
from the sibling `four-corners` gate) precisely because `dist/` is a committed
artifact: a green gate that didn't build could not tell you the bundle was stale.

`.githooks/pre-commit` is **path-aware**: it runs the PHP gate only if PHP-ish files
are staged, the frontend gate only if frontend-ish files are staged, and neither for
a docs-only commit (it still requires a running DDEV project). Never bypass with
`--no-verify`; `PACKAGE_SKIP_GATE=1` is a human emergency valve and **agents must
never set it**.

That hook file is a **copy** of the harness's canonical one. Do not edit it here —
edit `$CLAUDE_HARNESS_DIR/core/stacks/laravel-package/hooks/pre-commit` and re-run
that directory's `install.sh`.

`harness package-check` sweeps every first-party package: the gate, a
`--prefer-lowest` run proving the declared version floor really resolves, outdated
and vulnerability scans, and in-constraint updates behind a re-run of the gate. It
never tags a release. Run it before any app re-resolves its packages.

Full definition: `imports/package-quality-gate.md`. Skill: `/package-quality`.

## Dual mode: local store vs. hub

`Support\Hub::enabled()` is the single predicate, and it is exactly *"a hub URL is
configured"* — there is no second flag to drift out of sync.

**Local mode** (default, `YIKES_HUB_URL` empty): captures write markdown +
frontmatter to `config('yikes.path')` (default `base_path('.yikes')`), which is
**meant to be committed** — the notes *are* the queue. The local index at `/yikes`
is the triage UI: filter by status, approve, edit, delete.

**Hub mode** (`YIKES_HUB_URL` + `YIKES_HUB_TOKEN` + `YIKES_PROJECT`): a capture
still writes to `.yikes/` **first** — capture must never fail because the hub is
down — then pushes synchronously with a short timeout (`yikes.hub.timeout`, default
3s; a slow hub must not make capture feel broken). Unpushed bundles retry on the
next capture, via `php artisan yikes:flush`, and every ten minutes through the host's
scheduler if it runs one — no queue worker required. Push state lives in
`push/<note-id>.pushed` / `.conflict` markers. The local index/triage routes 404
behind `EnsureLocalIndexEnabled` (the hub owns triage); the FAB capture surface
stays. In hub mode `.yikes/` is an offline queue, not the system of record, and
consumers do not commit it.

Two safety rails in hub config, both deliberate:

- `hub.allow_in_tests` (default `false`) force-disables hub mode while the host app
  runs its suite, so a hub URL leaking in from `.env` can never spray test captures
  at a real hub. Tests exercising hub mode must opt in *and* fake the HTTP client.
- Screenshot size is clamped to `Hub::MAX_SCREENSHOT_BYTES` (5 MB) when hub mode is
  on, because the hub hard-413s above it — never let the client queue a file the hub
  would bounce.

Wire contract: `docs/hub-contract.md`. Design: `docs/SPEC-HUB.md`.

## Config lives at `config/yikes.php`

Top level, the conventional location — `mergeConfigFrom` and `publishes` both point
there, and the published target in a host app is `config/yikes.php`.

It used to sit at `src/Config/yikes.php`. Larastan cannot see a config directory in
that position, so all seven `env()` calls reported as "called outside the config
directory". `docs/SPEC.md` still says `src/Config/` — the spec is historical there;
the code is right. Never move it back, and keep `env()` calls confined to this file.

## Consumer-facing surface

- **Route prefixes**, both configurable: `yikes.route_prefix` (default `yikes`) and
  `yikes.checklists.route_prefix` (default `testing`). A host with its own
  `/{segments}` catch-all must reserve both, since package routes register first.
- **Reserved slugs**: `yikes.checklists.reserved_slugs` feeds a negative-lookahead
  into the `/{tester}` catch-all so a host can mount its own pages under the
  checklist prefix. Without a reservation the catch-all shadows them.
- **Everything is guest-reachable by design** (`middleware` default `['web']`) —
  QC testers file notes from auth/consumer pages, and checklist testers read their
  seeded credentials *before* logging in. The whole surface still 404s behind
  `EnsureYikesEnabled` when `YIKES_ENABLED` is false (default), and staging sits
  behind an access proxy. Consumers should add their own throttle middleware to the
  guest-writable capture routes.
- **Routes are registered unconditionally**; `EnsureYikesEnabled` answers 404 when
  disabled. That keeps enable/disable a runtime config concern, testable with plain
  `config()` calls instead of an app re-boot — don't "fix" it into boot-time gating.
- **CSP**: `yikes.csp_nonce` — `null` auto-detects Laravel's Vite nonce; a
  configured resolver is authoritative (`fn () => null` hard-disables detection).
  With no nonce the output is byte-identical to a non-CSP install.
- **`php artisan yikes:note`** is the agent capture path — same store, same push,
  stamped `source: agent` in context. `yikes:flush` retries the hub queue.

### Gotchas a newcomer trips on

- `YikesAssets::injectHtml()` takes **no arguments** (the `Request` param was dead
  and was removed) — see `CHANGELOG.md`.
- The auto-inject middleware skips any response already carrying
  `YikesAssets::MARKER`, so a host rendering the snippet manually with
  `ui.auto_inject` still on won't double-mount.
- Note filenames use the **last** 8 hex chars of the uuid7, not the first — uuid7's
  leading bytes are time bits shared by every id in a ~65-second window and would
  collide against the second-resolution timestamp prefix.
- The capture routes are guest-writable, so the authenticated user can be null and a
  consumer's user model is theirs: read name/email via `getAttribute()` with
  defaults, never as declared properties.

## Testing

Pest + Orchestra Testbench. **This package has no database** — no Eloquent, no
migrations, flat files only — so `tests/TestCase.php` binds an in-memory SQLite
connection purely to satisfy the framework, and points `yikes.path` at a per-test
temp directory. Do not reach for the Postgres setup other first-party packages use.

```bash
ddev composer test
ddev exec vendor/bin/pest --filter=HubPushTest
```

Frontend tests are Vitest under `happy-dom`, colocated in
`resources/js/**/__tests__/*.spec.ts`:

```bash
ddev exec npm run test:run
ddev exec npm run typecheck
```

There is no `ddev artisan` and no `ddev pest` here — those are app commands.

## The bundled `process-yikes` skill

`skills/process-yikes/SKILL.md` ships **inside this package**. It teaches Claude Code
to work the note queue: report counts by status, implement only `approved` notes (or
exactly the one note the user names, which auto-approves it), read the captured
context and screenshots, commit referencing the note id, then flip the note to `done`
with a `resolution` block. It also handles directed maintenance — merge, edit, delete.

Consumers install it as a committed symlink into `.claude/skills/process-yikes` →
`vendor/robinsonryan/yikes/skills/process-yikes` (README has the `.gitignore`
re-include ordering, which matters). Changing the note lifecycle, the frontmatter
schema, or the status vocabulary means updating this skill in the same commit.

## Releases

**Never tag.** Automation may update, gate, commit and push a branch, then report
"ready to tag" with a suggested version. Ryan cuts every tag. A version number is a
claim about behavior that a green gate cannot substantiate.

Behavior changes land in `CHANGELOG.md` in the commit that makes them.

Version constraints here (`illuminate/* ^12.0|^13.0`, `orchestra/testbench
^10.0|^11.0`, `php ^8.2`) are consistent and tested — don't widen them casually.
Widening to Pest 5 / PHPUnit 13 is queued deliberately in `QUEUE.md`.

## Reference package

`~/dev/php/packages/robinsonryan/hey-you/` is the reference implementation —
service provider shape, Testbench setup, tool configs, table prefixing. Read it
before inventing a variant.

## Quick reference

- **DDEV**: `ddev start`, `ddev ssh`
- **PHP gate**: `ddev composer quality`
- **Frontend gate**: `ddev exec npm run quality`
- **PHP tests**: `ddev composer test`
- **Island build**: `ddev exec npm run build` (then stage `dist/`)
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
