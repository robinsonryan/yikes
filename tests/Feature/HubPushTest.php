<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Support\PushQueue;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

/** A real (1x1 transparent) PNG so content-sniffing validation passes. */
function tinyPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}

function createStoredNote(?string $stateJson = null): Note
{
    return app(NoteRepository::class)->create(
        body: 'Queued while the hub was down',
        title: 'Queued note',
        type: NoteType::Bug,
        context: ['url' => 'https://example.test/page'],
        createdBy: ['name' => 'Tester', 'email' => 'tester@example.test'],
        stateJson: $stateJson,
    );
}

beforeEach(function (): void {
    config()->set('yikes.enabled', true);
    config()->set('yikes.hub.url', 'https://hub.test');
    config()->set('yikes.hub.token', 'ingest-token');
    config()->set('yikes.hub.project', 'example-app');
});

describe('push on capture', function (): void {
    it('pushes the note bundle and its screenshots to the hub', function (): void {
        Http::fake([
            'hub.test/api/v1/notes/*/screenshots' => Http::response(['position' => 1], 201),
            'hub.test/api/v1/notes' => Http::response(['status' => 'new'], 201),
        ]);

        $pending = $this->postJson('/yikes/screenshots', [
            'screenshot' => UploadedFile::fake()->createWithContent('screenshot.png', tinyPng()),
        ])->assertCreated()->json('id');

        $this->postJson('/yikes/notes', [
            'body' => 'The headline is wrong',
            'type' => 'layout',
            'context' => ['url' => 'https://example.test/about'],
            'state' => '{"cart":{"items":2}}',
            'screenshots' => [$pending],
        ])->assertCreated();

        $note = app(NoteRepository::class)->all()->sole();

        Http::assertSentInOrder([
            fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/notes',
            fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/notes/' . $note->id . '/screenshots',
        ]);

        Http::assertSent(function (Request $request) use ($note): bool {
            if ($request->url() !== 'https://hub.test/api/v1/notes') {
                return false;
            }

            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer ingest-token')
                && $data['id'] === $note->id
                && $data['type'] === 'layout'
                && $data['body'] === 'The headline is wrong'
                && $data['captured_at'] === $note->createdAt->toIso8601String()
                && $data['created_by'] === ['name' => 'Unknown', 'email' => '']
                && $data['state'] === ['cart' => ['items' => 2]]
                && $data['screenshot_count'] === 1
                // The contract forbids status/resolution on ingest.
                && ! array_key_exists('status', $data)
                && ! array_key_exists('resolution', $data);
        });

        Http::assertSent(function (Request $request) use ($note): bool {
            if (! str_ends_with($request->url(), '/notes/' . $note->id . '/screenshots')) {
                return false;
            }

            $position = collect($request->data())->firstWhere('name', 'position');

            return $request->isMultipart()
                && $request->hasFile('file')
                && (int) ($position['contents'] ?? 0) === 1;
        });

        expect(app(PushQueue::class)->isPushed($note->id))->toBeTrue()
            ->and(app(PushQueue::class)->queued())->toBeEmpty();
    });

    it('still captures when the hub is down and leaves the bundle queued', function (): void {
        Http::fake(function (): void {
            throw new ConnectionException('hub is down');
        });

        $this->postJson('/yikes/notes', [
            'body' => 'Filed while offline',
            'type' => 'bug',
        ])->assertCreated();

        $note = app(NoteRepository::class)->all()->sole();

        expect($note->body)->toBe('Filed while offline')
            ->and(app(PushQueue::class)->isPushed($note->id))->toBeFalse()
            ->and(app(PushQueue::class)->queued())->toHaveCount(1);
    });

    it('retries previously queued bundles on the next capture', function (): void {
        $stranded = createStoredNote();

        Http::fake(['hub.test/api/v1/notes' => Http::response([], 201)]);

        $this->postJson('/yikes/notes', ['body' => 'Fresh capture', 'type' => 'bug'])
            ->assertCreated();

        Http::assertSentCount(2);
        expect(app(PushQueue::class)->isPushed($stranded->id))->toBeTrue()
            ->and(app(PushQueue::class)->queued())->toBeEmpty();
    });
});

describe('yikes:flush', function (): void {
    it('replays queued bundles, treating 200 and 201 as pushed', function (): void {
        $replayed = createStoredNote(stateJson: '{"a":1}');
        $fresh = createStoredNote();

        Http::fakeSequence()
            ->push([], 200)  // idempotent replay of a half-pushed bundle
            ->push([], 201);

        $this->artisan('yikes:flush')
            ->expectsOutputToContain('Pushed 2, still queued 0, conflicts 0.')
            ->assertSuccessful();

        expect(app(PushQueue::class)->isPushed($replayed->id))->toBeTrue()
            ->and(app(PushQueue::class)->isPushed($fresh->id))->toBeTrue();
    });

    it('marks a 409 terminal and never retries that bundle', function (): void {
        $conflicted = createStoredNote();

        Http::fake(['hub.test/api/v1/notes' => Http::response(['message' => 'payload differs'], 409)]);

        $this->artisan('yikes:flush')
            ->expectsOutputToContain('Pushed 0, still queued 0, conflicts 1.')
            ->assertSuccessful();

        expect(app(PushQueue::class)->isConflict($conflicted->id))->toBeTrue()
            ->and(is_file(app(NoteRepository::class)->path('push/' . $conflicted->id . '.conflict')))->toBeTrue();

        // A later flush must not touch the conflicted bundle again.
        Http::fake();
        $this->artisan('yikes:flush')->assertSuccessful();
        Http::assertNothingSent();
    });

    it('keeps a throttled bundle queued for the next run', function (): void {
        $throttled = createStoredNote();

        // Throttled on the first flush, accepted on the second.
        Http::fakeSequence()
            ->push(['message' => 'slow down'], 429)
            ->push([], 201);

        $this->artisan('yikes:flush')
            ->expectsOutputToContain('Pushed 0, still queued 1, conflicts 0.')
            ->assertSuccessful();

        expect(app(PushQueue::class)->queued())->toHaveCount(1);

        $this->artisan('yikes:flush')
            ->expectsOutputToContain('Pushed 1, still queued 0, conflicts 0.')
            ->assertSuccessful();

        expect(app(PushQueue::class)->isPushed($throttled->id))->toBeTrue();
    });

    it('stops early when the hub is unreachable', function (): void {
        createStoredNote();
        createStoredNote();

        $attempts = 0;
        Http::fake(function () use (&$attempts): void {
            $attempts++;

            throw new ConnectionException('hub is down');
        });

        $this->artisan('yikes:flush')
            ->expectsOutputToContain('Pushed 0, still queued 2, conflicts 0.')
            ->assertSuccessful();

        // One connect timeout is enough signal — no per-bundle retry storm.
        expect($attempts)->toBe(1);
    });

    it('does nothing in local mode', function (): void {
        config()->set('yikes.hub.url', '');
        Http::fake();

        $this->artisan('yikes:flush')
            ->expectsOutputToContain('local mode')
            ->assertSuccessful();

        Http::assertNothingSent();
    });
});

describe('local mode is untouched', function (): void {
    beforeEach(function (): void {
        config()->set('yikes.hub.url', '');
    });

    it('never talks to a hub on capture', function (): void {
        Http::fake();

        $this->postJson('/yikes/notes', ['body' => 'Plain local note', 'type' => 'bug'])
            ->assertCreated();

        Http::assertNothingSent();
        expect(app(NoteRepository::class)->all())->toHaveCount(1);
    });

    it('keeps the index UI', function (): void {
        $this->get('/yikes')->assertOk();
    });
});

describe('hub mode disables the local index UI', function (): void {
    it('404s the index and triage endpoints', function (): void {
        $note = createStoredNote();

        $this->get('/yikes')->assertNotFound();
        $this->patchJson('/yikes/notes/' . $note->id, ['body' => 'edited'])->assertNotFound();
        $this->patchJson('/yikes/notes/' . $note->id . '/status', ['status' => 'approved'])->assertNotFound();
        $this->deleteJson('/yikes/notes/' . $note->id)->assertNotFound();
        $this->deleteJson('/yikes/notes/completed')->assertNotFound();
    });

    it('keeps the FAB capture surface up', function (): void {
        Http::fake(['hub.test/*' => Http::response([], 201)]);

        $this->postJson('/yikes/screenshots', [
            'screenshot' => UploadedFile::fake()->createWithContent('screenshot.png', tinyPng()),
        ])->assertCreated();

        $this->postJson('/yikes/notes', ['body' => 'Captured in hub mode', 'type' => 'idea'])
            ->assertCreated();

        $this->get('/yikes/assets/yikes.js')->assertOk();
    });

    it('advertises hub mode and the capped screenshot size to the island', function (): void {
        config()->set('yikes.max_screenshot_kb', 8192);

        \Illuminate\Support\Facades\Route::middleware('web')->get('/hub-host-page', fn () => response(
            '<!DOCTYPE html><html><head><title>Host</title></head><body></body></html>',
        )->header('Content-Type', 'text/html; charset=utf-8'));

        $content = (string) $this->get('/hub-host-page')->getContent();

        expect($content)->toContain('"hub":true')
            // min(8192 KB, hub hard cap) = 5 MB.
            ->toContain('"maxScreenshotBytes":5242880');
    });
});
