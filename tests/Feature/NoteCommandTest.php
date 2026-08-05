<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Support\PushQueue;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('yikes.enabled', true);
});

describe('yikes:note', function (): void {
    it('files a note from the CLI with agent attribution', function (): void {
        $this->artisan('yikes:note', [
            'body' => 'The org index N+1s on wards; out of scope for the queue rework.',
            '--title' => 'Org index N+1',
            '--type' => 'refactor',
            '--context' => ['file=app/Http/Controllers/OrgController.php:42', 'branch=develop'],
        ])->assertSuccessful();

        $note = app(NoteRepository::class)->all()->sole();

        expect($note->body)->toBe('The org index N+1s on wards; out of scope for the queue rework.')
            ->and($note->title)->toBe('Org index N+1')
            ->and($note->type)->toBe(NoteType::Refactor)
            ->and($note->createdBy)->toBe(['name' => 'Claude Code', 'email' => ''])
            ->and($note->context['source'])->toBe('agent')
            ->and($note->context['file'])->toBe('app/Http/Controllers/OrgController.php:42')
            ->and($note->context['branch'])->toBe('develop');
    });

    it('defaults to an untitled bug note', function (): void {
        $this->artisan('yikes:note', ['body' => 'Something is off.'])->assertSuccessful();

        $note = app(NoteRepository::class)->all()->sole();

        expect($note->title)->toBeNull()
            ->and($note->type)->toBe(NoteType::Bug)
            ->and($note->context)->toBe(['source' => 'agent']);
    });

    it('records a custom creator name via --by', function (): void {
        $this->artisan('yikes:note', ['body' => 'Filed by a subagent.', '--by' => 'Claude (reviewer)'])
            ->assertSuccessful();

        expect(app(NoteRepository::class)->all()->sole()->createdBy['name'])->toBe('Claude (reviewer)');
    });

    it('refuses to file while yikes is disabled', function (): void {
        config()->set('yikes.enabled', false);

        $this->artisan('yikes:note', ['body' => 'Should not land.'])->assertFailed();

        expect(app(NoteRepository::class)->all())->toBeEmpty();
    });

    it('rejects an unknown type and names the valid ones', function (): void {
        $this->artisan('yikes:note', ['body' => 'x', '--type' => 'enhancement'])
            ->expectsOutputToContain('bug, layout, idea, refactor')
            ->assertFailed();

        expect(app(NoteRepository::class)->all())->toBeEmpty();
    });

    it('rejects a context entry without a key=value shape', function (): void {
        $this->artisan('yikes:note', ['body' => 'x', '--context' => ['no-equals-sign']])
            ->assertFailed();

        expect(app(NoteRepository::class)->all())->toBeEmpty();
    });

    it('pushes to the hub on capture in hub mode', function (): void {
        config()->set('yikes.hub.url', 'https://hub.test');
        config()->set('yikes.hub.token', 'ingest-token');
        config()->set('yikes.hub.project', 'example-app');
        config()->set('yikes.hub.allow_in_tests', true);

        Http::fake(['hub.test/api/v1/notes' => Http::response([], 201)]);

        $this->artisan('yikes:note', ['body' => 'Deferred work item.'])->assertSuccessful();

        $note = app(NoteRepository::class)->all()->sole();

        Http::assertSentCount(1);
        expect(app(PushQueue::class)->isPushed($note->id))->toBeTrue();
    });

    it('keeps the note and stays successful when the hub is down', function (): void {
        config()->set('yikes.hub.url', 'https://hub.test');
        config()->set('yikes.hub.token', 'ingest-token');
        config()->set('yikes.hub.project', 'example-app');
        config()->set('yikes.hub.allow_in_tests', true);

        Http::fake(function (): void {
            throw new Illuminate\Http\Client\ConnectionException('hub is down');
        });

        $this->artisan('yikes:note', ['body' => 'Filed while the hub naps.'])->assertSuccessful();

        $note = app(NoteRepository::class)->all()->sole();

        expect(app(PushQueue::class)->isPushed($note->id))->toBeFalse()
            ->and(app(PushQueue::class)->queued())->toHaveCount(1);
    });

    it('never talks to a hub in local mode', function (): void {
        Http::fake();

        $this->artisan('yikes:note', ['body' => 'Local-only note.'])->assertSuccessful();

        Http::assertNothingSent();
    });
});
