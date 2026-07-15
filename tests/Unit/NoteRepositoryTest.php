<?php

declare(strict_types=1);

use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Tests\TestCase;
use Illuminate\Http\UploadedFile;

uses(TestCase::class);

function repository(): NoteRepository
{
    return resolve(NoteRepository::class);
}

function createNote(NoteRepository $repository, array $overrides = []): Note
{
    return $repository->create(
        body: $overrides['body'] ?? 'Something is off on this page.',
        title: $overrides['title'] ?? 'A yikes note',
        type: $overrides['type'] ?? NoteType::Bug,
        context: $overrides['context'] ?? ['url' => 'https://example.test/page', 'page' => 'example/Page'],
        createdBy: $overrides['createdBy'] ?? ['name' => 'QC User', 'email' => 'qc@example.com'],
        stateJson: $overrides['stateJson'] ?? null,
        pendingScreenshotIds: $overrides['pendingScreenshotIds'] ?? [],
        pendingOwnerId: $overrides['pendingOwnerId'] ?? 'user-1',
        status: $overrides['status'] ?? NoteStatus::New,
    );
}

describe('bootstrap', function () {
    it('initializes the directory with a .gitignore and README on first write', function () {
        $repo = repository();

        createNote($repo);

        expect(file_get_contents($repo->basePath() . '/.gitignore'))->toContain('screenshots/pending/')
            ->and(is_file($repo->basePath() . '/README.md'))->toBeTrue();
    });
});

describe('create', function () {
    it('writes a note file named by timestamp and uuid prefix', function () {
        $repo = repository();

        $note = createNote($repo);

        $files = glob($repo->basePath() . '/notes/*.md');

        expect($files)->toHaveCount(1)
            ->and(basename((string) $files[0]))->toMatch('/^\d{8}-\d{6}-' . substr($note->id, -8) . '\.md$/')
            ->and($note->status)->toBe(NoteStatus::New);
    });

    it('persists the state snapshot to its own file and records the relative path', function () {
        $repo = repository();

        $note = createNote($repo, ['stateJson' => '{"cart":{"items":3}}']);

        expect($note->stateFile)->toBe('state/' . $note->id . '.json')
            ->and(file_get_contents($repo->path((string) $note->stateFile)))->toBe('{"cart":{"items":3}}');
    });

    it('leaves state_file null when no snapshot is given', function () {
        $note = createNote(repository());

        expect($note->stateFile)->toBeNull();
    });

    it('normalizes a blank title to null', function () {
        $note = createNote(repository(), ['title' => '   ']);

        expect($note->title)->toBeNull();
    });

    it('persists an explicit initial status', function () {
        $repo = repository();

        $note = createNote($repo, ['status' => NoteStatus::Approved]);

        expect($note->status)->toBe(NoteStatus::Approved)
            ->and($repo->find($note->id)?->status)->toBe(NoteStatus::Approved);
    });
});

describe('all / find', function () {
    it('lists notes newest first and filters by status', function () {
        $repo = repository();

        $first = createNote($repo, ['title' => 'first']);
        $second = createNote($repo, ['title' => 'second']);
        $repo->updateStatus($second->id, NoteStatus::Approved);

        $all = $repo->all();
        $approved = $repo->all(NoteStatus::Approved);

        expect($all)->toHaveCount(2)
            ->and($approved)->toHaveCount(1)
            ->and($approved->first()?->id)->toBe($second->id)
            ->and($all->pluck('id')->all())->toContain($first->id);
    });

    it('finds a note by id and returns null for unknown or malformed ids', function () {
        $repo = repository();

        $note = createNote($repo);

        expect($repo->find($note->id)?->title)->toBe('A yikes note')
            ->and($repo->find('01890a5d-ac96-774b-bcce-b30209999999'))->toBeNull()
            ->and($repo->find('../../../etc/passwd'))->toBeNull();
    });
});

describe('updateStatus', function () {
    it('rewrites the frontmatter status on disk', function () {
        $repo = repository();

        $note = createNote($repo);
        $updated = $repo->updateStatus($note->id, NoteStatus::OnHold);

        expect($updated?->status)->toBe(NoteStatus::OnHold)
            ->and($repo->find($note->id)?->status)->toBe(NoteStatus::OnHold);
    });

    it('returns null for an unknown note', function () {
        expect(repository()->updateStatus('01890a5d-ac96-774b-bcce-b30209999999', NoteStatus::Done))->toBeNull();
    });
});

describe('update', function () {
    it('rewrites the title and body, leaving everything else untouched', function () {
        $repo = repository();

        $note = createNote($repo, ['stateJson' => '{"a":1}']);
        $repo->updateStatus($note->id, NoteStatus::Approved);

        $updated = $repo->update($note->id, 'Sharper title', 'More thoughts after saving.');

        expect($updated?->title)->toBe('Sharper title')
            ->and($updated?->body)->toBe('More thoughts after saving.')
            ->and($updated?->status)->toBe(NoteStatus::Approved)
            ->and($updated?->stateFile)->toBe('state/' . $note->id . '.json')
            ->and($repo->find($note->id)?->body)->toBe('More thoughts after saving.');
    });

    it('normalizes a blank title to null', function () {
        $repo = repository();

        $note = createNote($repo);
        $updated = $repo->update($note->id, '   ', 'body only');

        expect($updated?->title)->toBeNull();
    });

    it('returns null for an unknown note', function () {
        expect(repository()->update('01890a5d-ac96-774b-bcce-b30209999999', null, 'x'))->toBeNull();
    });
});

describe('delete', function () {
    it('removes the note, its state file, and its screenshots directory', function () {
        $repo = repository();

        $pendingId = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('shot.png'));
        $note = createNote($repo, [
            'stateJson' => '{"a":1}',
            'pendingScreenshotIds' => [$pendingId],
        ]);

        expect($repo->delete($note->id))->toBeTrue()
            ->and($repo->find($note->id))->toBeNull()
            ->and(is_file($repo->path('state/' . $note->id . '.json')))->toBeFalse()
            ->and(is_dir($repo->path('screenshots/' . $note->id)))->toBeFalse();
    });

    it('returns false for an unknown note', function () {
        expect(repository()->delete('01890a5d-ac96-774b-bcce-b30209999999'))->toBeFalse();
    });
});

describe('deleteByStatus', function () {
    it('removes every note with the given status and leaves the rest', function () {
        $repo = repository();

        $done = createNote($repo, ['title' => 'done', 'status' => NoteStatus::Done, 'stateJson' => '{"a":1}']);
        $alsoDone = createNote($repo, ['title' => 'also done', 'status' => NoteStatus::Done]);
        $kept = createNote($repo, ['title' => 'kept', 'status' => NoteStatus::Approved]);

        expect($repo->deleteByStatus(NoteStatus::Done))->toBe(2)
            ->and($repo->find($done->id))->toBeNull()
            ->and($repo->find($alsoDone->id))->toBeNull()
            ->and(is_file($repo->path('state/' . $done->id . '.json')))->toBeFalse()
            ->and($repo->find($kept->id)?->title)->toBe('kept');
    });

    it('returns zero when nothing matches', function () {
        expect(repository()->deleteByStatus(NoteStatus::Done))->toBe(0);
    });
});

describe('pending screenshots', function () {
    it('stores, lists, and deletes pending screenshots per user', function () {
        $repo = repository();

        $first = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('a.png'));
        $second = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('b.png'));
        $other = $repo->storePendingScreenshot('user-2', UploadedFile::fake()->image('c.png'));

        expect($repo->listPending('user-1'))->toBe([$first, $second])
            ->and($repo->listPending('user-2'))->toBe([$other])
            ->and($repo->deletePending('user-1', $first))->toBeTrue()
            ->and($repo->listPending('user-1'))->toBe([$second]);
    });

    it('cannot delete another user\'s pending screenshot', function () {
        $repo = repository();

        $id = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('a.png'));

        expect($repo->deletePending('user-2', $id))->toBeFalse()
            ->and($repo->listPending('user-1'))->toBe([$id]);
    });

    it('attaches selected pending screenshots to a new note in order', function () {
        $repo = repository();

        $first = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('a.png'));
        $second = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('b.png'));
        $excluded = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('c.png'));

        $note = createNote($repo, ['pendingScreenshotIds' => [$first, $second]]);

        expect($note->screenshots)->toHaveCount(2)
            ->and($note->screenshots[0])->toMatch('/^screenshots\/' . $note->id . '\/001-\d{8}-\d{6}\.png$/')
            ->and($note->screenshots[1])->toMatch('/^screenshots\/' . $note->id . '\/002-\d{8}-\d{6}\.png$/')
            ->and(is_file($repo->path($note->screenshots[0])))->toBeTrue()
            // Attached files leave the pending area; excluded ones stay.
            ->and($repo->listPending('user-1'))->toBe([$excluded]);
    });
});

describe('path traversal guards', function () {
    it('rejects malformed note ids and filenames for screenshot serving', function () {
        $repo = repository();

        $pendingId = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('a.png'));
        $note = createNote($repo, ['pendingScreenshotIds' => [$pendingId]]);
        $file = basename($note->screenshots[0]);

        expect($repo->screenshotPath($note->id, $file))->not->toBeNull()
            ->and($repo->screenshotPath('../notes', $file))->toBeNull()
            ->and($repo->screenshotPath($note->id, '../../notes/whatever.md'))->toBeNull()
            ->and($repo->screenshotPath($note->id, '001-20260711-103000.php'))->toBeNull()
            ->and($repo->screenshotPath($note->id, '..\\..\\evil.png'))->toBeNull();
    });

    it('rejects malformed pending ids and owner ids', function () {
        $repo = repository();

        $id = $repo->storePendingScreenshot('user-1', UploadedFile::fake()->image('a.png'));

        expect($repo->pendingPath('user-1', $id))->not->toBeNull()
            ->and($repo->pendingPath('user-1', '../../.env'))->toBeNull()
            ->and($repo->pendingPath('../user-1', $id))->toBeNull();
    });

    it('never resolves a path outside the screenshots tree', function () {
        $repo = repository();

        createNote($repo); // ensures the tree exists

        // A file that exists but lives outside screenshots/ must not resolve
        // even if identifiers were somehow accepted.
        $resolved = $repo->screenshotPath('01890a5d-ac96-774b-bcce-b302099a8057', '001-20260711-103000.png');

        expect($resolved)->toBeNull();
    });
});
