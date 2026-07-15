<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\PushOutcome;

/**
 * The hub-mode offline queue over the flat-file store.
 *
 * There is no separate queue storage: the `.yikes/` store itself is the
 * queue, and pushed-state lives in tiny marker files beside it —
 *
 *   push/<note-id>.pushed     bundle fully on the hub (note + screenshots)
 *   push/<note-id>.conflict   terminal 409/413 — never retried again
 *
 * A note with neither marker is queued. Markers are separate files on
 * purpose: they survive process restarts, they never touch the note file's
 * user-visible content, and replaying a half-pushed bundle is safe because
 * the hub is idempotent on note id and (note id, position).
 */
final class PushQueue
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly HubClient $client,
    ) {}

    /**
     * Push every queued bundle, oldest first. Stops early when the hub is
     * unreachable — burning a full timeout per remaining bundle helps nobody.
     *
     * @return array{pushed: int, queued: int, conflicts: int}
     */
    public function flush(): array
    {
        $result = ['pushed' => 0, 'queued' => 0, 'conflicts' => 0];
        $queue = $this->queued();

        foreach ($queue as $index => $note) {
            $outcome = $this->push($note);

            if ($outcome === PushOutcome::Unreachable) {
                // This bundle plus everything not yet attempted stays queued.
                $result['queued'] += count($queue) - $index;

                break;
            }

            match ($outcome) {
                PushOutcome::Pushed => $result['pushed'] += 1,
                PushOutcome::Conflict => $result['conflicts'] += 1,
                default => $result['queued'] += 1,
            };
        }

        return $result;
    }

    /**
     * Push one bundle: the note first, then its screenshots ascending by
     * position. The bundle is only marked pushed once EVERYTHING landed;
     * a partial push stays queued and replays idempotently next time.
     */
    public function push(Note $note): PushOutcome
    {
        $outcome = $this->client->pushNote($note, $this->state($note));

        if ($outcome !== PushOutcome::Pushed) {
            return $this->settle($note, $outcome);
        }

        foreach ($this->positionedScreenshots($note) as $position => $absolutePath) {
            $outcome = $this->client->pushScreenshot($note->id, $position, $absolutePath);

            if ($outcome !== PushOutcome::Pushed) {
                return $this->settle($note, $outcome);
            }
        }

        $this->writeMarker($note->id, 'pushed');

        return PushOutcome::Pushed;
    }

    /**
     * Queued bundles, oldest first (the hub works its queue oldest-first
     * too). Only capture-fresh statuses are queue members: a pre-hub store
     * full of triaged notes must not be bulk-pushed as `new` just because
     * hub mode got switched on — migrating history is an explicit import.
     *
     * @return list<Note>
     */
    public function queued(): array
    {
        return $this->notes->all()
            ->filter(fn (Note $note): bool => in_array($note->status, [NoteStatus::New, NoteStatus::Approved], true)
                && ! $this->isPushed($note->id)
                && ! $this->isConflict($note->id))
            ->reverse()
            ->values()
            ->all();
    }

    public function isPushed(string $id): bool
    {
        return is_file($this->markerPath($id, 'pushed'));
    }

    public function isConflict(string $id): bool
    {
        return is_file($this->markerPath($id, 'conflict'));
    }

    private function settle(Note $note, PushOutcome $outcome): PushOutcome
    {
        if ($outcome === PushOutcome::Conflict) {
            Log::warning('yikes: hub rejected note bundle terminally (409/413); leaving a .conflict marker.', [
                'note' => $note->id,
            ]);

            $this->writeMarker($note->id, 'conflict');
        }

        return $outcome;
    }

    /** Inline state for the wire: the note's state file decoded, or null. */
    private function state(Note $note): mixed
    {
        if ($note->stateFile === null) {
            return null;
        }

        $contents = @file_get_contents($this->notes->path($note->stateFile));

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The note's screenshots as position => absolute path, ascending.
     * Position comes from the stored filename's NNN- prefix (capture order).
     *
     * @return array<int, string>
     */
    private function positionedScreenshots(Note $note): array
    {
        $positioned = [];

        foreach ($note->screenshots as $relative) {
            $absolute = $this->notes->path($relative);

            if (! is_file($absolute)) {
                continue;
            }

            $positioned[(int) substr(basename($relative), 0, 3)] = $absolute;
        }

        ksort($positioned);

        return $positioned;
    }

    private function writeMarker(string $id, string $kind): void
    {
        $path = $this->markerPath($id, $kind);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return; // Losing a marker only costs an idempotent replay.
        }

        file_put_contents($path, json_encode([
            $kind . '_at' => CarbonImmutable::now()->toIso8601String(),
        ]) . "\n");
    }

    private function markerPath(string $id, string $kind): string
    {
        return $this->notes->path('push/' . strtolower($id) . '.' . $kind);
    }
}
