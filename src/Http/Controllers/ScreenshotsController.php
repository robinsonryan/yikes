<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Controllers;

use RobinsonRyan\Yikes\Http\Requests\StoreScreenshotRequest;
use RobinsonRyan\Yikes\Support\NoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * On-demand screenshot capture. Uploads land in a per-user pending area and
 * are attached to a note at save time. Serving goes through the repository's
 * strict id/filename validation + realpath containment (the files live
 * outside public/ on purpose).
 */
final class ScreenshotsController
{
    public function __construct(private readonly NoteRepository $notes) {}

    public function storePending(StoreScreenshotRequest $request): JsonResponse
    {
        $file = $request->file('screenshot');
        assert($file instanceof UploadedFile);

        $id = $this->notes->storePendingScreenshot($this->ownerId($request), $file);

        return response()->json([
            'id' => $id,
            'url' => route('yikes.screenshots.showPending', ['id' => $id]),
            'pending' => $this->notes->listPending($this->ownerId($request)),
        ], 201);
    }

    public function destroyPending(Request $request, string $id): Response
    {
        abort_unless($this->notes->deletePending($this->ownerId($request), $id), 404);

        return response()->noContent();
    }

    public function showPending(Request $request, string $id): BinaryFileResponse
    {
        $path = $this->notes->pendingPath($this->ownerId($request), $id);

        abort_if($path === null, 404);

        return response()->file($path);
    }

    public function show(string $note, string $file): BinaryFileResponse
    {
        $path = $this->notes->screenshotPath($note, $file);

        abort_if($path === null, 404);

        return response()->file($path);
    }

    private function ownerId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
