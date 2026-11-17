# Changelog

All notable changes to this package are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Behavior changes land here in the commit that makes them, not at tag time.

## [Unreleased]

### Fixed

- **Picking an element on Android no longer opens Chrome's Touch to Search.**
  While picking, one transparent overlay now covers the page, so a tap lands on
  the overlay instead of on page text, and the page's own click handlers never
  see the tap. With a mouse the overlay steps aside once the mouse moves, so
  hover menus still open and the wheel still scrolls inner panels; a touch drag
  scrolls an inner panel too. What changed: the page no longer sees the
  `pointerdown` of a pick (nor, now, any part of the click, even in its own
  capture-phase listeners), and until the first mouse move the crosshair cursor
  comes from the overlay. Everything else about picking is unchanged: one
  element, the same highlight and hint, a widget stacked above the page is
  picked like any element, Escape cancels.

### Changed

- The pill's drag, the element picker, screenshot capture and the screenshot
  size cap now come from the shared capture kit, `robinsonryan/capture`, a new
  composer dev dependency bundled into `dist/` at build time. Internal — no
  consumer-facing change; consumers still never run npm.

- **Development and test database is now Postgres 18, never MariaDB or SQLite.**
  Internal — no consumer-facing behavior changes, and the package still has no
  database of its own (notes remain flat files). The DDEV `db` container was
  MariaDB 10.11 and the Testbench connection was in-memory SQLite; both were
  inherited tool defaults nobody chose, and both advertised a database this
  package does not have. DDEV now provisions Postgres 18 with a `testing`
  database created on start, and `tests/TestCase.php` binds `pgsql`. Contributors
  need `ddev restart` once; a fresh clone needs nothing. Verified by
  `harness db-check` and guarded by `tests/Unit/TestDatabaseEngineTest.php`.

## [0.7.0] - 2026-08-08

### Changed

- **`YikesAssets::injectHtml()` no longer takes a `Request`.** The parameter was
  never read. Callers outside the package must drop the argument. Surfaced by the
  first Rector pass this package has ever had, and taken rather than reverted
  because the parameter was genuinely dead — the only in-package caller
  (`InjectYikesAssets` middleware) is updated.
- Package config moved from `src/Config/yikes.php` to the conventional
  `config/yikes.php`. Internal — the service provider's `mergeConfigFrom` and
  `publishes` paths moved with it, and the published target (`config/yikes.php`
  in the host app) is unchanged. Every other first-party package already used
  the top-level location; larastan could not see the old one, which is why all
  seven `env()` calls read as "called outside the config directory".
- PHP floor lowered `^8.3` → `^8.2` (harness decision D-C). Native types were
  removed from 12 class constants, which is 8.3-only syntax — it analysed clean
  on the 8.4 dev container and would have fatalled on a consumer running 8.2.

### Fixed

- `ChecklistResultStore` step lookups were annotated `array<string, ...>` while
  the keys are numeric. PHP coerces numeric-string keys to ints, so the declared
  type could never hold them, and static analysis derived a permanently-null
  status — the pass/fail/total roll-up was unverifiable as written. Annotation
  corrected to `array<array-key, ...>`.
- `NoteRepository::storePendingScreenshot()` passed a possibly-null
  `UploadedFile::extension()` straight into `strtolower()`.
- `NotesController` read `name`/`email` as declared properties off the
  authenticated user. The capture routes are deliberately guest-writable, so the
  user can be null, and a consumer's user model is theirs — both now go through
  `getAttribute()` with defaults.
- `yikes:note` cast raw console input to `string`. Console options are
  `array|bool|string|null`; `--type` with no value hands back a bool and an array
  cast would have been fatal. Input is now narrowed rather than cast.

### Added

- The full quality gate: `composer quality` = `lint:check` → `analyze` →
  `refactor:check` → `test`, with `phpstan.neon` (level 8, `phpVersion: 80200`),
  `pint.json` and `rector.php`. Nothing in this package had ever been analysed.
