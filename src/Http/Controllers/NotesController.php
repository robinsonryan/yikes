<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Controllers;

use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Http\Requests\StoreNoteRequest;
use RobinsonRyan\Yikes\Http\Requests\UpdateNoteRequest;
use RobinsonRyan\Yikes\Http\Requests\UpdateNoteStatusRequest;
use RobinsonRyan\Yikes\Support\Hub;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Support\PushQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class NotesController
{
    public function __construct(private readonly NoteRepository $notes) {}

    /**
     * Minimal notes index: list (newest first), filter by status. Serves the
     * island's Blade shell; the island refetches the same route with an
     * Accept: application/json header after mutations.
     */
    public function index(Request $request): View|JsonResponse
    {
        $status = NoteStatus::tryFrom($request->string('status')->toString());

        $props = [
            'notes' => $this->notes->all($status)
                ->map(fn (Note $note): array => $this->presentNote($note))
                ->all(),
            'filters' => ['status' => $status?->value],
            'statuses' => NoteStatus::values(),
            'types' => NoteType::values(),
            'pendingScreenshots' => $this->notes->listPending($this->ownerId($request)),
        ];

        if ($request->wantsJson()) {
            return response()->json($props);
        }

        return view('yikes::page', [
            'title' => 'Yikes',
            'component' => 'Index',
            'props' => $props,
        ]);
    }

    public function store(StoreNoteRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $user = $request->user();

        /** @var list<string> $pendingIds */
        $pendingIds = $data['screenshots'] ?? [];

        /** @var array<string, mixed> $context */
        $context = $data['context'] ?? [];

        $state = $data['state'] ?? null;

        $this->notes->create(
            body: (string) $data['body'],
            title: isset($data['title']) ? (string) $data['title'] : null,
            type: NoteType::tryFrom((string) ($data['type'] ?? '')) ?? NoteType::Bug,
            context: $context,
            createdBy: [
                'name' => (string) ($user?->name ?? 'Unknown'),
                'email' => (string) ($user?->email ?? ''),
            ],
            stateJson: config('yikes.capture_state', true) && is_string($state) ? $state : null,
            pendingScreenshotIds: $pendingIds,
            pendingOwnerId: $this->ownerId($request),
            status: NoteStatus::tryFrom((string) ($data['status'] ?? '')) ?? NoteStatus::New,
        );

        // Hub mode: the note is safely on disk — now try a synchronous push.
        // Flushing (rather than pushing just this note) also retries bundles
        // stranded by earlier hub downtime. Any trouble is swallowed: capture
        // must NEVER fail because the hub is down or slow.
        if (Hub::enabled()) {
            try {
                app(PushQueue::class)->flush();
            } catch (\Throwable $e) {
                Log::warning('yikes: push-on-capture failed; bundle stays queued.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => 'Yikes! Note saved.'], 201);
    }

    public function update(UpdateNoteRequest $request, string $note): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $updated = $this->notes->update(
            $note,
            isset($data['title']) ? (string) $data['title'] : null,
            (string) $data['body'],
        );

        abort_unless($updated instanceof Note, 404);

        return response()->json(['message' => 'Note updated.']);
    }

    public function updateStatus(UpdateNoteStatusRequest $request, string $note): JsonResponse
    {
        $status = NoteStatus::from((string) $request->validated('status'));

        $updated = $this->notes->updateStatus($note, $status);

        abort_unless($updated instanceof Note, 404);

        return response()->json(['message' => 'Note marked ' . $status->value . '.']);
    }

    public function destroy(string $note): JsonResponse
    {
        abort_unless($this->notes->delete($note), 404);

        return response()->json(['message' => 'Note deleted.']);
    }

    /**
     * Bulk-remove every done note (the index's "Clear completed" action).
     */
    public function destroyCompleted(): JsonResponse
    {
        $count = $this->notes->deleteByStatus(NoteStatus::Done);

        return response()->json(['message' => $count === 0
            ? 'No completed notes to clear.'
            : 'Cleared ' . $count . ' completed ' . ($count === 1 ? 'note' : 'notes') . '.']);
    }

    /**
     * Serialize a note for the index page: the frontmatter shape + body,
     * with screenshots expanded to { file, url } for direct linking.
     *
     * @return array<string, mixed>
     */
    private function presentNote(Note $note): array
    {
        return [
            ...$note->toArray(),
            'screenshots' => array_map(static fn (string $relative): array => [
                'file' => basename($relative),
                'url' => route('yikes.screenshots.show', [
                    'note' => $note->id,
                    'file' => basename($relative),
                ]),
            ], $note->screenshots),
        ];
    }

    private function ownerId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
