<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Yikes\Data\Note;
use RobinsonRyan\Yikes\Enums\PushOutcome;

/**
 * HTTP push client for the hub's ingest API (docs/hub-contract.md).
 *
 * One method per contract endpoint; every call is bounded by a short
 * connect+read timeout so a slow hub can never stall a capture request.
 * All outcomes are expressed as PushOutcome — this class never throws for
 * hub/network trouble.
 */
final class HubClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * POST /api/v1/notes — the note bundle (everything but screenshot bytes).
     *
     * Notes are created `new` on the hub; the contract forbids sending
     * `status` or `resolution` on ingest, so neither field appears here
     * (the flat file's `on-hold` vs wire `on_hold` normalization is thereby
     * moot for ingest — status simply never goes on the wire).
     */
    public function pushNote(Note $note, mixed $state): PushOutcome
    {
        try {
            $response = $this->request()->post('/api/v1/notes', [
                'id' => $note->id,
                'title' => $note->title,
                'type' => $note->type->value,
                'body' => $note->body,
                'created_by' => [
                    'name' => (string) ($note->createdBy['name'] ?? ''),
                    'email' => (string) ($note->createdBy['email'] ?? ''),
                ],
                'captured_at' => $note->createdAt->toIso8601String(),
                // JSON `{}` (not `[]`) when the captured context is empty.
                'context' => $note->context === [] ? new \stdClass() : $note->context,
                'state' => $state,
                'screenshot_count' => count($note->screenshots),
            ]);
        } catch (ConnectionException) {
            return PushOutcome::Unreachable;
        }

        return $this->outcome($response);
    }

    /**
     * POST /api/v1/notes/{id}/screenshots — one PNG, multipart, positioned.
     */
    public function pushScreenshot(string $noteId, int $position, string $absolutePath): PushOutcome
    {
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            // The file vanished from under us — nothing to retry.
            return PushOutcome::Conflict;
        }

        try {
            $response = $this->request()
                ->attach('file', $contents, basename($absolutePath), ['Content-Type' => 'image/png'])
                ->post('/api/v1/notes/' . $noteId . '/screenshots', [
                    'position' => $position,
                ]);
        } catch (ConnectionException) {
            return PushOutcome::Unreachable;
        }

        return $this->outcome($response);
    }

    private function outcome(Response $response): PushOutcome
    {
        return match (true) {
            // 201 created, 200 idempotent replay — both mean "it's there".
            $response->status() === 200,
            $response->status() === 201 => PushOutcome::Pushed,
            // 409 payload mismatch / 413 over the hard cap: identical bytes
            // can never succeed — terminal for this bundle.
            $response->status() === 409,
            $response->status() === 413 => PushOutcome::Conflict,
            // 429 / 5xx / auth or validation trouble: queued until later
            // (a fixed token or a recovered hub makes the next flush win).
            default => PushOutcome::RetryLater,
        };
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds);
    }
}
