<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RobinsonRyan\Yikes\Support\Hub;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the local index/triage surface OFF in hub mode.
 *
 * With a hub configured, the hub owns triage — the package's own index UI
 * (list, edit, status changes, delete) answers 404 as if it did not exist,
 * while the FAB's capture routes stay up. Same pattern as
 * EnsureYikesEnabled: routes are always registered, the mode is a plain
 * runtime config concern.
 */
final class EnsureLocalIndexEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Hub::enabled(), 404);

        return $next($request);
    }
}
