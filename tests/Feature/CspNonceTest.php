<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use RobinsonRyan\Yikes\Support\CspNonce;
use RobinsonRyan\Yikes\Support\YikesAssets;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

/** Explicit-resolver fixture for the class-string path (config:cache safe). */
final class FixedNonceResolver
{
    public function __invoke(): ?string
    {
        return 'class-string-nonce';
    }
}

beforeEach(function (): void {
    config()->set('yikes.enabled', true);

    Route::middleware('web')->get('/host-page', fn () => response(
        '<!DOCTYPE html><html><head><title>Host</title></head><body><h1>Host page</h1></body></html>',
    )->header('Content-Type', 'text/html; charset=utf-8'));
});

describe('without a nonce', function (): void {
    it('emits byte-identical no-nonce output on host pages', function (): void {
        $content = (string) $this->get('/host-page')->assertOk()->getContent();

        expect($content)->toContain('<script>' . YikesAssets::MARKER)
            ->and($content)->toContain('<script type="module" src="')
            ->and($content)->not->toContain('nonce');
    });

    it('emits byte-identical no-nonce output in the island Blade shell', function (): void {
        $content = (string) $this->get('/yikes')->assertOk()->getContent();

        expect($content)->toContain('<style>html, body')
            ->and($content)->toContain('<script>' . YikesAssets::MARKER)
            ->and($content)->not->toContain('nonce');
    });
});

describe('Vite nonce auto-detection', function (): void {
    it('stamps the Vite nonce on the host-page injection with zero config', function (): void {
        Vite::useCspNonce('vite-nonce-123');

        $content = (string) $this->get('/host-page')->assertOk()->getContent();

        expect($content)->toContain('<script nonce="vite-nonce-123">' . YikesAssets::MARKER)
            ->and($content)->toContain('<script type="module" nonce="vite-nonce-123" src="');
    });

    it('stamps the Vite nonce on the island Blade shell, inline style included', function (): void {
        Vite::useCspNonce('vite-nonce-123');

        $content = (string) $this->get('/yikes')->assertOk()->getContent();

        expect($content)->toContain('<style nonce="vite-nonce-123">html, body')
            ->and($content)->toContain('<script nonce="vite-nonce-123">' . YikesAssets::MARKER)
            ->and($content)->toContain('<script type="module" nonce="vite-nonce-123" src="');
    });
});

describe('explicit resolver', function (): void {
    it('wins over the Vite nonce when a callable is configured', function (): void {
        Vite::useCspNonce('vite-nonce-123');
        config()->set('yikes.csp_nonce', fn (): string => 'explicit-nonce-456');

        $content = (string) $this->get('/host-page')->assertOk()->getContent();

        expect($content)->toContain('<script nonce="explicit-nonce-456">' . YikesAssets::MARKER)
            ->and($content)->not->toContain('vite-nonce-123');
    });

    it('resolves an invokable class-string through the container', function (): void {
        config()->set('yikes.csp_nonce', FixedNonceResolver::class);

        $content = (string) $this->get('/host-page')->assertOk()->getContent();

        expect($content)->toContain('<script nonce="class-string-nonce">' . YikesAssets::MARKER);
    });

    it('hard-disables auto-detection when the resolver returns null', function (): void {
        Vite::useCspNonce('vite-nonce-123');
        config()->set('yikes.csp_nonce', fn (): ?string => null);

        $content = (string) $this->get('/host-page')->assertOk()->getContent();

        expect($content)->toContain('<script>' . YikesAssets::MARKER)
            ->and($content)->not->toContain('nonce');
    });

    it('escapes the nonce for the HTML attribute context', function (): void {
        config()->set('yikes.csp_nonce', fn (): string => 'abc"><script>evil');

        expect(CspNonce::attr())->toBe(' nonce="abc&quot;&gt;&lt;script&gt;evil"');
    });

    it('rejects a non-callable resolver loudly', function (): void {
        config()->set('yikes.csp_nonce', 'not-a-class-or-callable-xyz');

        CspNonce::resolve();
    })->throws(InvalidArgumentException::class);
});
