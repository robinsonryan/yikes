<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * All filesystem I/O for the yikes flat-file store.
 *
 * Layout under the base path (config `yikes.path`):
 *   notes/<YYYYMMDD-HHMMSS>-<uuid-first-8>.md
 *   state/<note-id>.json
 *   screenshots/<note-id>/<seq>-<timestamp>.<ext>
 *   screenshots/pending/<user-id>/<uuid>.<ext>
 *
 * Every externally-supplied identifier (note ids, pending ids, screenshot
 * filenames, pending-owner ids) is validated against a strict pattern before
 * it touches a path, and served files are additionally realpath-contained
 * within the screenshots tree.
 */
final class NoteRepository
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private const string SCREENSHOT_FILE_PATTERN = '/^\d{3}-\d{8}-\d{6}\.(png|jpg|jpeg|webp)$/D';

    private const string OWNER_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** @var list<string> */
    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public function __construct(private readonly string $basePath) {}

    /*
    |--------------------------------------------------------------------------
    | Notes
    |--------------------------------------------------------------------------
    */

    /**
     * Create a note: attach the selected pending screenshots, persist the
     * state snapshot (when given), and write the note file.
     *
     * @param  array{name: string, email: string}  $createdBy
     * @param  array<string, mixed>  $context
     * @param  list<string>  $pendingScreenshotIds  pending screenshot ids (uuids) to attach
     */
    public function create(
        string $body,
        ?string $title,
        NoteType $type,
        array $context,
        array $createdBy,
        ?string $stateJson,
        array $pendingScreenshotIds = [],
        string $pendingOwnerId = '',
        NoteStatus $status = NoteStatus::New,
    ): Note {
        $this->initialize();

        $id = (string) Str::uuid7();
        $createdAt = CarbonImmutable::now();

        $screenshots = $pendingScreenshotIds === []
            ? []
            : $this->attachPendingScreenshots($id, $pendingOwnerId, $pendingScreenshotIds);

        $stateFile = null;

        if ($stateJson !== null && $stateJson !== '') {
            $stateFile = 'state/' . $id . '.json';
            $this->ensureDirectory($this->path('state'));
            file_put_contents($this->path($stateFile), $stateJson);
        }

        $note = new Note(
            id: $id,
            title: $title !== null && trim($title) !== '' ? $title : null,
            type: $type,
            status: $status,
            createdAt: $createdAt,
            createdBy: $createdBy,
            context: $context,
            stateFile: $stateFile,
            screenshots: $screenshots,
            resolution: null,
            body: $body,
        );

        // The uuid's LAST 8 chars (random bits) — uuid7's first 8 are the
        // millisecond timestamp's high bits, identical for every note created
        // within the same ~65s window, which would collide with the shared
        // second-resolution timestamp prefix and overwrite files.
        $filename = sprintf('%s-%s.md', $createdAt->format('Ymd-His'), substr($id, -8));
        $this->ensureDirectory($this->path('notes'));
        file_put_contents($this->path('notes/' . $filename), $note->toFileContents());

        return $note;
    }

    /**
     * All notes, newest first, optionally filtered by status.
     *
     * @return Collection<int, Note>
     */
    public function all(?NoteStatus $status = null): Collection
    {
        $files = glob($this->path('notes/*.md')) ?: [];

        return collect($files)
            ->map(function (string $file): ?Note {
                $contents = file_get_contents($file);

                try {
                    return $contents === false ? null : Note::fromFileContents($contents);
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter(fn (?Note $note): bool => $note instanceof Note
                && ($status === null || $note->status === $status))
            ->sortByDesc(fn (Note $note) => $note->createdAt->getTimestamp())
            ->values();
    }

    public function find(string $id): ?Note
    {
        $path = $this->findNotePath($id);

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : Note::fromFileContents($contents);
    }

    /**
     * Rewrite the note's frontmatter with the new status.
     * Returns null when the note does not exist.
     */
    public function updateStatus(string $id, NoteStatus $status): ?Note
    {
        $path = $this->findNotePath($id);

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $note = Note::fromFileContents($contents)->withStatus($status);
        file_put_contents($path, $note->toFileContents());

        return $note;
    }

    /**
     * Rewrite the note's title and body, leaving everything else untouched.
     * Returns null when the note does not exist.
     */
    public function update(string $id, ?string $title, string $body): ?Note
    {
        $path = $this->findNotePath($id);

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $note = Note::fromFileContents($contents)->withContent(
            title: $title !== null && trim($title) !== '' ? $title : null,
            body: $body,
        );
        file_put_contents($path, $note->toFileContents());

        return $note;
    }

    /**
     * Delete a note plus its state file and screenshots directory.
     */
    public function delete(string $id): bool
    {
        $path = $this->findNotePath($id);

        if ($path === null) {
            return false;
        }

        $note = null;
        $contents = file_get_contents($path);

        if ($contents !== false) {
            try {
                $note = Note::fromFileContents($contents);
            } catch (\Throwable) {
                // Corrupt frontmatter — still delete the note file itself.
            }
        }

        unlink($path);

        if ($note?->stateFile !== null && is_file($this->path($note->stateFile))) {
            unlink($this->path($note->stateFile));
        }

        $this->deleteDirectory($this->path('screenshots/' . $id));

        return true;
    }

    /**
     * Delete every note with the given status (plus state files and
     * screenshots). Returns the number of notes removed.
     */
    public function deleteByStatus(NoteStatus $status): int
    {
        return $this->all($status)
            ->filter(fn (Note $note): bool => $this->delete($note->id))
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Screenshots
    |--------------------------------------------------------------------------
    */

    /**
     * Store an uploaded screenshot in the per-user pending area.
     * Returns the pending id (uuid).
     */
    public function storePendingScreenshot(string $ownerId, UploadedFile $file): string
    {
        $this->initialize();

        $extension = strtolower($file->extension());

        if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported screenshot type: ' . $extension);
        }

        $id = (string) Str::uuid7();
        $directory = $this->pendingDirectory($ownerId);
        $this->ensureDirectory($directory);

        $file->move($directory, $id . '.' . $extension);

        return $id;
    }

    /**
     * Pending screenshot ids for a user, oldest first (capture order).
     *
     * @return list<string>
     */
    public function listPending(string $ownerId): array
    {
        // No GLOB_BRACE: it's a glibc extension that does not exist on musl
        // (Alpine — the production image), where the constant is undefined and
        // the whole /yikes surface 500s. One glob per extension instead.
        $files = [];

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $files = [...$files, ...(glob($this->pendingDirectory($ownerId) . '/*.' . $extension) ?: [])];
        }

        usort($files, fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        return array_values(array_filter(
            array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $files),
            fn (string $id): bool => preg_match(self::UUID_PATTERN, $id) === 1,
        ));
    }

    public function deletePending(string $ownerId, string $id): bool
    {
        $path = $this->pendingPath($ownerId, $id);

        if ($path === null) {
            return false;
        }

        unlink($path);

        return true;
    }

    /**
     * Move pending screenshots (by id, in the given order) into the note's
     * screenshot directory. Unknown ids are skipped.
     *
     * @param  list<string>  $pendingIds
     * @return list<string> relative paths of the attached screenshots
     */
    public function attachPendingScreenshots(string $noteId, string $ownerId, array $pendingIds): array
    {
        if (preg_match(self::UUID_PATTERN, strtolower($noteId)) !== 1) {
            return [];
        }

        $attached = [];
        $sequence = 1;

        foreach ($pendingIds as $pendingId) {
            $source = $this->pendingPath($ownerId, $pendingId);

            if ($source === null) {
                continue;
            }

            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $relative = sprintf(
                'screenshots/%s/%03d-%s.%s',
                strtolower($noteId),
                $sequence,
                CarbonImmutable::now()->format('Ymd-His'),
                $extension,
            );

            $this->ensureDirectory(dirname($this->path($relative)));
            rename($source, $this->path($relative));

            $attached[] = $relative;
            $sequence++;
        }

        return $attached;
    }

    /**
     * Absolute path of an attached screenshot, or null when the identifiers
     * are malformed, the file is missing, or it escapes the screenshots tree.
     */
    public function screenshotPath(string $noteId, string $filename): ?string
    {
        if (preg_match(self::UUID_PATTERN, strtolower($noteId)) !== 1) {
            return null;
        }

        if (preg_match(self::SCREENSHOT_FILE_PATTERN, $filename) !== 1) {
            return null;
        }

        return $this->containedScreenshotPath('screenshots/' . strtolower($noteId) . '/' . $filename);
    }

    /**
     * Absolute path of a pending screenshot owned by the given user, or null.
     */
    public function pendingPath(string $ownerId, string $id): ?string
    {
        if (preg_match(self::UUID_PATTERN, strtolower($id)) !== 1) {
            return null;
        }

        $directory = $this->pendingDirectory($ownerId);

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $candidate = $directory . '/' . strtolower($id) . '.' . $extension;

            if (is_file($candidate)) {
                $relative = str_replace($this->basePath() . '/', '', $candidate);

                return $this->containedScreenshotPath($relative);
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Bootstrap & paths
    |--------------------------------------------------------------------------
    */

    /**
     * Create the base directory with its .gitignore and README on first write.
     */
    public function initialize(): void
    {
        $base = $this->basePath();

        if (! is_dir($base)) {
            $this->ensureDirectory($base);
        }

        if (! is_file($base . '/.gitignore')) {
            file_put_contents($base . '/.gitignore', <<<'GITIGNORE'
                # Pending screenshots are transient (snapped but not yet attached to a
                # note) — never commit them. Everything else in .yikes/ IS committed.
                screenshots/pending/

                GITIGNORE);
        }

        if (! is_file($base . '/README.md')) {
            file_put_contents($base . '/README.md', <<<'README'
                # .yikes/ — in-app dev/QC notes

                Notes captured from inside the app via the "Yikes" button (package
                `robinsonryan/yikes`). Each note is a markdown file with YAML
                frontmatter describing the page context it was captured on.

                - `notes/` — one `.md` file per note (frontmatter + the user's note text)
                - `state/` — Pinia state snapshot per note (`<note-id>.json`)
                - `screenshots/<note-id>/` — screenshots attached to a note (committed)
                - `screenshots/pending/` — snapped but unattached (gitignored, transient)

                This directory is committed on purpose: run the `process-yikes` skill to
                have Claude triage and implement the `approved` notes.

                README);
        }
    }

    public function basePath(): string
    {
        return rtrim($this->basePath, '/');
    }

    public function path(string $relative): string
    {
        return $this->basePath() . '/' . ltrim($relative, '/');
    }

    /**
     * Locate the note file for an id by filename hint, verified by parsing.
     */
    private function findNotePath(string $id): ?string
    {
        $id = strtolower($id);

        if (preg_match(self::UUID_PATTERN, $id) !== 1) {
            return null;
        }

        $candidates = glob($this->path('notes/*-' . substr($id, -8) . '.md')) ?: [];

        foreach ($candidates as $candidate) {
            $contents = file_get_contents($candidate);

            if ($contents === false) {
                continue;
            }

            try {
                if (Note::fromFileContents($contents)->id === $id) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Resolve a relative screenshot path and require it to stay inside the
     * screenshots tree (realpath containment — the last defence against
     * traversal even if a validation regex ever regresses).
     */
    private function containedScreenshotPath(string $relative): ?string
    {
        $real = realpath($this->path($relative));
        $root = realpath($this->path('screenshots'));

        if ($real === false || $root === false) {
            return null;
        }

        if (! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private function pendingDirectory(string $ownerId): string
    {
        $safeOwner = preg_match(self::OWNER_PATTERN, $ownerId) === 1 ? $ownerId : sha1($ownerId);

        return $this->path('screenshots/pending/' . $safeOwner);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = scandir($directory) ?: [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . '/' . $file;

            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
