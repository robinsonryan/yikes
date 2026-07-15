<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
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
