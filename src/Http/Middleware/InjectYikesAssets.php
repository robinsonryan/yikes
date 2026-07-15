<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RobinsonRyan\Yikes\Support\YikesAssets;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appends the yikes island bootstrap (config + module tag) to every HTML
 * response while yikes is enabled, so the FAB exists on every host page
 * with zero host build integration.
 *
 * Skips non-HTML responses, redirects, streamed/binary responses, and any
 * page that already carries the marker (the package's own Blade shell).
 */
final class InjectYikesAssets
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) config('yikes.enabled', false)) {
            return $response;
        }

        if ($response->isRedirection() || ! $response->isSuccessful()) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '' || str_contains($content, YikesAssets::MARKER)) {
            return $response;
        }

        $snippet = YikesAssets::injectHtml($request);

        $position = strripos($content, '</body>');

        if ($position === false) {
            return $response;
        }

        $response->setContent(substr($content, 0, $position) . $snippet . substr($content, $position));
        $response->headers->remove('Content-Length');

        return $response;
    }
}
