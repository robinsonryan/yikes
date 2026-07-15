<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Builds the bootstrap snippet that boots the self-contained yikes island:
 * a `window.__YIKES__` config object plus the module tag for the prebuilt
 * bundle (served by AssetsController from the package's dist/).
 *
 * Used by both the InjectYikesAssets middleware (host pages) and the
 * package's own Blade shell (yikes pages) — the middleware skips responses
 * that already carry the marker.
 */
final class YikesAssets
{
    public const MARKER = 'window.__YIKES__';

    public static function distPath(string $file = ''): string
    {
        return dirname(__DIR__, 2) . '/dist' . ($file === '' ? '' : '/' . $file);
    }

    /** Cache-busting token derived from the built bundle. */
    public static function version(): string
    {
        $entry = self::distPath('yikes.js');

        $mtime = is_file($entry) ? (int) filemtime($entry) : 0;

        return substr(md5($mtime . '|' . (is_file($entry) ? filesize($entry) : 0)), 0, 12);
    }

    public static function assetUrl(string $file): string
    {
        return route('yikes.assets', ['file' => $file]) . '?v=' . self::version();
    }

    /**
     * The full injectable HTML: config + module script tag.
     */
    public static function injectHtml(Request $request): string
    {
        $maxScreenshotBytes = (int) config('yikes.max_screenshot_kb', 4096) * 1024;

        if (Hub::enabled()) {
            // The hub hard-413s any screenshot over its cap — never let the
            // client store/push a file the hub would bounce.
            $maxScreenshotBytes = min($maxScreenshotBytes, Hub::MAX_SCREENSHOT_BYTES);
        }

        $config = [
            'base' => url((string) config('yikes.route_prefix', 'yikes')),
            'testingBase' => url((string) config('yikes.checklists.route_prefix', 'testing')),
            'csrf' => csrf_token(),
            'route' => Route::currentRouteName(),
            'darkSelector' => config('yikes.ui.dark_selector'),
            'name' => (string) config('app.name', 'Laravel'),
            // Hub mode: the island hides the local index link and the
            // fast-track toggle (triage lives on the hub).
            'hub' => Hub::enabled(),
            'maxScreenshotBytes' => $maxScreenshotBytes,
        ];

        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return "\n<script>" . self::MARKER . ' = ' . $json . ';</script>'
            . "\n" . '<script type="module" src="' . self::assetUrl('yikes.js') . '"></script>' . "\n";
    }
}
