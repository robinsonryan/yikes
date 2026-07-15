<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Controllers;

use RobinsonRyan\Yikes\Support\YikesAssets;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the prebuilt island bundle straight from the package's dist/ —
 * no publish step, no host build. Filenames are whitelisted by the route
 * pattern; realpath containment guards traversal regardless.
 */
final class AssetsController
{
    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'map' => 'application/json',
    ];

    public function show(string $file): BinaryFileResponse
    {
        $dist = realpath(YikesAssets::distPath());
        $path = $dist === false ? false : realpath($dist . '/' . $file);

        abort_if($path === false || ! str_starts_with($path, $dist . DIRECTORY_SEPARATOR), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        abort_unless(isset(self::TYPES[$extension]), 404);

        return response()->file($path, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
