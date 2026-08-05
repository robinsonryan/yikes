<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RobinsonRyan\Yikes\Http\Rules\BoundedContext;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Support\YikesAssets;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('yikes.enabled', true);
});

describe('notes index', function (): void {
    it('serves the island Blade shell', function (): void {
        $response = $this->get('/yikes');

        $response->assertOk();
        $response->assertSee('id="yikes-app"', false);
        $response->assertSee('data-component="Index"', false);
        $response->assertSee(YikesAssets::MARKER, false);
    });

    it('serves the raw props as JSON for island refetches', function (): void {
        app(NoteRepository::class)->create(
            body: 'Something broke',
            title: 'Broken thing',
            type: RobinsonRyan\Yikes\Enums\NoteType::Bug,
            context: ['url' => 'https://example.test/page'],
            createdBy: ['name' => 'Tester', 'email' => 'tester@example.test'],
            stateJson: null,
        );

        $response = $this->getJson('/yikes');

        $response->assertOk();
        $response->assertJsonCount(1, 'notes');
        $response->assertJsonPath('notes.0.title', 'Broken thing');
    });

    it('404s while yikes is disabled', function (): void {
        config()->set('yikes.enabled', false);

        $this->get('/yikes')->assertNotFound();
    });
});

describe('storing notes', function (): void {
    it('stores a note and answers JSON', function (): void {
        $response = $this->postJson('/yikes/notes', [
            'body' => 'The headline is wrong',
            'type' => 'layout',
            'context' => [
                'url' => 'https://example.test/about',
                'title' => 'About us',
                'element' => [
                    'selector' => '#hero > h1',
                    'tag' => 'h1',
                    'text' => 'Wrong headline',
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Yikes! Note saved.');

        $notes = app(NoteRepository::class)->all();
        expect($notes)->toHaveCount(1)
            ->and($notes->first()->context['element']['selector'])->toBe('#hero > h1')
            ->and($notes->first()->context['title'])->toBe('About us');
    });

    it('answers 422 JSON on validation failure', function (): void {
        $this->postJson('/yikes/notes', ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    });

    it('stores host-provided context keys the package has never heard of, verbatim', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Wrong org in the header',
            'context' => [
                'url' => 'https://example.test/callings',
                'surface' => 'vueadmin',
                'organization' => ['id' => 'org_42', 'name' => 'Sunset Ward'],
                'tenant_slug' => 'sunset',
                'seats' => 12,
                'beta' => true,
                'trial_ends' => null,
            ],
        ])->assertCreated();

        $note = app(NoteRepository::class)->all()->first();

        expect($note->context)->toBe([
            'url' => 'https://example.test/callings',
            'surface' => 'vueadmin',
            'organization' => ['id' => 'org_42', 'name' => 'Sunset Ward'],
            'tenant_slug' => 'sunset',
            'seats' => 12,
            'beta' => true,
            'trial_ends' => null,
        ]);

        // …and verbatim in the frontmatter on disk, not just in the parsed DTO.
        $file = (string) file_get_contents((string) collect(glob(app(NoteRepository::class)->path('notes/*.md')))->first());
        expect($file)->toContain('surface: vueadmin')
            ->and($file)->toContain('tenant_slug: sunset');
    });

    it('stores unknown context keys holding flat arrays of scalars', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Abilities look wrong',
            'context' => [
                'abilities' => ['callings.view', 'callings.assign', 'members.view'],
                'flags' => [true, false],
            ],
        ])->assertCreated();

        $note = app(NoteRepository::class)->all()->first();

        expect($note->context['abilities'])->toBe(['callings.view', 'callings.assign', 'members.view'])
            ->and($note->context['flags'])->toBe([true, false]);
    });

    it('keeps the known nbss-style account/department keys working', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Pricing tab misaligned',
            'context' => [
                'account' => ['id' => '9', 'name' => 'Acme'],
                'department' => ['id' => '3', 'name' => 'Parts'],
                'dark_mode' => true,
                'viewport' => ['width' => 1440, 'height' => 900],
            ],
        ])->assertCreated();

        $note = app(NoteRepository::class)->all()->first();

        expect($note->context['account'])->toBe(['id' => '9', 'name' => 'Acme'])
            ->and($note->context['department'])->toBe(['id' => '3', 'name' => 'Parts'])
            ->and($note->context['dark_mode'])->toBeTrue()
            ->and($note->context['viewport'])->toBe(['width' => 1440, 'height' => 900]);
    });

    it('rejects a context with more keys than the cap', function (): void {
        $context = [];

        foreach (range(1, BoundedContext::MAX_KEYS + 1) as $index) {
            $context['key_' . $index] = 'value';
        }

        $this->postJson('/yikes/notes', ['body' => 'Too much context', 'context' => $context])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('rejects context values nested deeper than one flat array level', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Deeply nested context',
            'context' => ['organization' => ['unit' => ['id' => 7]]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('rejects a context that is a bare list instead of named keys', function (): void {
        $this->postJson('/yikes/notes', ['body' => 'List context', 'context' => ['a', 'b']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('rejects context keys longer than the key-length cap', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Absurd key',
            'context' => [str_repeat('k', BoundedContext::MAX_KEY_LENGTH + 1) => 'value'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('rejects context string values longer than the value cap', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Absurd value',
            'context' => ['blob' => str_repeat('x', BoundedContext::MAX_STRING_LENGTH + 1)],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('still rejects a malformed viewport on the known keys', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Bad viewport',
            'context' => ['viewport' => ['width' => 'wide', 'height' => 900]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('still rejects a malformed element on the known keys', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Bad element',
            'context' => ['element' => ['selector' => str_repeat('a', 1025), 'tag' => 'h1']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });

    it('still rejects a non-boolean dark_mode on the known keys', function (): void {
        $this->postJson('/yikes/notes', [
            'body' => 'Bad dark mode',
            'context' => ['dark_mode' => 'sometimes'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    });
});

describe('assets', function (): void {
    it('serves the prebuilt bundle with immutable caching', function (): void {
        $response = $this->get('/yikes/assets/yikes.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    });

    it('rejects filenames outside the whitelist pattern', function (): void {
        $this->get('/yikes/assets/..%2Fcomposer.json')->assertNotFound();
        $this->get('/yikes/assets/nope.php')->assertNotFound();
    });
});

describe('inject middleware', function (): void {
    beforeEach(function (): void {
        Route::middleware('web')->get('/host-page', fn () => response(
            '<!DOCTYPE html><html><head><title>Host</title></head><body><h1>Host page</h1></body></html>',
        )->header('Content-Type', 'text/html; charset=utf-8'));
    });

    it('injects the island bootstrap into host HTML responses', function (): void {
        $response = $this->get('/host-page');

        $response->assertOk();
        $response->assertSee(YikesAssets::MARKER, false);
        $response->assertSee('/yikes/assets/yikes.js', false);
        // The snippet lands before </body>.
        expect(strpos((string) $response->getContent(), YikesAssets::MARKER))
            ->toBeLessThan(strpos((string) $response->getContent(), '</body>'));
    });

    it('does not inject while yikes is disabled', function (): void {
        config()->set('yikes.enabled', false);

        $this->get('/host-page')->assertDontSee(YikesAssets::MARKER, false);
    });

    it('does not inject into JSON responses', function (): void {
        Route::middleware('web')->get('/host-json', fn () => response()->json(['ok' => true]));

        $this->get('/host-json')->assertExactJson(['ok' => true]);
    });

    it('does not double-inject the package pages', function (): void {
        $content = (string) $this->get('/yikes')->getContent();

        expect(substr_count($content, YikesAssets::MARKER))->toBe(1);
    });
});
