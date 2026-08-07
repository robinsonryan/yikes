# Changelog

All notable changes to this package are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Behavior changes land here in the commit that makes them, not at tag time.

## [Unreleased]

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
