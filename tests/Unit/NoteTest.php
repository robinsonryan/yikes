<?php

declare(strict_types=1);

use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Tests\TestCase;
use Carbon\CarbonImmutable;

uses(TestCase::class);

function makeNote(array $overrides = []): Note
{
    $defaults = [
        'id' => '01890a5d-ac96-774b-bcce-b302099a8057',
        'title' => 'Broken totals row',
        'type' => NoteType::Bug,
        'status' => NoteStatus::New,
        'createdAt' => CarbonImmutable::parse('2026-07-11T10:30:00-06:00'),
        'createdBy' => ['name' => 'Ryan Robinson', 'email' => 'ryan@example.com'],
        'context' => [
            'url' => 'https://nbss.ddev.site/account/billing?month=2026-06',
            'route' => 'billing.index',
            'page' => 'account/billing/Index',
            'account' => ['id' => 'acc-1', 'name' => 'Test Account'],
            'department' => null,
            'dark_mode' => true,
            'viewport' => ['width' => 1440, 'height' => 900],
            'user_agent' => 'Mozilla/5.0',
        ],
        'stateFile' => 'state/01890a5d-ac96-774b-bcce-b302099a8057.json',
        'screenshots' => ['screenshots/01890a5d-ac96-774b-bcce-b302099a8057/001-20260711-103000.png'],
        'resolution' => null,
        'body' => "The **totals row** is misaligned.\n\n- happens only in dark mode\n- see screenshot",
    ];

    return new Note(...array_merge($defaults, $overrides));
}

describe('Note file round-trip', function () {
    it('round-trips every field through toFileContents/fromFileContents', function () {
        $note = makeNote();

        $parsed = Note::fromFileContents($note->toFileContents());

        expect($parsed->id)->toBe($note->id)
            ->and($parsed->title)->toBe($note->title)
            ->and($parsed->type)->toBe($note->type)
            ->and($parsed->status)->toBe($note->status)
            ->and($parsed->createdAt->toIso8601String())->toBe($note->createdAt->toIso8601String())
            ->and($parsed->createdBy)->toBe($note->createdBy)
            ->and($parsed->context)->toBe($note->context)
            ->and($parsed->stateFile)->toBe($note->stateFile)
            ->and($parsed->screenshots)->toBe($note->screenshots)
            ->and($parsed->resolution)->toBeNull()
            ->and($parsed->body)->toBe($note->body);
    });

    it('round-trips null title, null state file, and empty screenshots', function () {
        $note = makeNote(['title' => null, 'stateFile' => null, 'screenshots' => []]);

        $parsed = Note::fromFileContents($note->toFileContents());

        expect($parsed->title)->toBeNull()
            ->and($parsed->stateFile)->toBeNull()
            ->and($parsed->screenshots)->toBe([]);
    });

    it('round-trips a resolution block', function () {
        $note = makeNote([
            'status' => NoteStatus::Done,
            'resolution' => [
                'commit' => 'abc1234',
                'note' => 'Fixed the flex alignment',
                'completed_at' => '2026-07-12T09:00:00-06:00',
            ],
        ]);

        $parsed = Note::fromFileContents($note->toFileContents());

        expect($parsed->status)->toBe(NoteStatus::Done)
            ->and($parsed->resolution)->toBe($note->resolution);
    });

    it('starts the file with YAML frontmatter delimiters', function () {
        $contents = makeNote()->toFileContents();

        expect($contents)->toStartWith("---\n")
            ->and($contents)->toContain("\n---\n\n");
    });

    it('rejects contents without frontmatter', function () {
        Note::fromFileContents('just a markdown body, no frontmatter');
    })->throws(InvalidArgumentException::class);

    it('rejects frontmatter missing required fields', function () {
        Note::fromFileContents("---\ntitle: whoops\n---\nbody");
    })->throws(InvalidArgumentException::class);
});

describe('Note immutability helpers', function () {
    it('withStatus returns a new instance with only status changed', function () {
        $note = makeNote();

        $approved = $note->withStatus(NoteStatus::Approved);

        expect($approved)->not->toBe($note)
            ->and($approved->status)->toBe(NoteStatus::Approved)
            ->and($note->status)->toBe(NoteStatus::New)
            ->and($approved->body)->toBe($note->body);
    });

    it('toArray exposes the frontmatter shape plus body', function () {
        $array = makeNote()->toArray();

        expect($array)->toHaveKeys([
            'id', 'title', 'type', 'status', 'created_at', 'created_by',
            'context', 'state_file', 'screenshots', 'resolution', 'body',
        ])->and($array['type'])->toBe('bug')
            ->and($array['status'])->toBe('new');
    });
});
