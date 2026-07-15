<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every yikes route behind the `yikes.enabled` config flag.
 *
 * The routes are always registered (so enable/disable is a plain runtime
 * config concern, testable without re-booting the app); when the feature is
 * off the whole surface answers 404 as if it did not exist.
 */
final class EnsureYikesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('yikes.enabled', false), 404);

        return $next($request);
    }
}
