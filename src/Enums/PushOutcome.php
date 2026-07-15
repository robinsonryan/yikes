<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Enums;

/**
 * Result of pushing one note bundle (or one of its parts) to the hub.
 */
enum PushOutcome
{
    /** Accepted (201) or idempotent replay (200) — the bundle is on the hub. */
    case Pushed;

    /**
     * Transient failure — throttled (429), server error (5xx), auth/config
     * problem (401/403/422). Leave the bundle queued; a later flush retries.
     */
    case RetryLater;

    /**
     * Terminal for this bundle: the hub holds a DIFFERENT payload under the
     * same id (409), or a screenshot is over the hard cap (413). Retrying
     * with the same bytes can never succeed — mark it and stop.
     */
    case Conflict;

    /**
     * The hub could not be reached at all (connection/timeout). Like
     * RetryLater for the bundle, but a flush loop should stop early —
     * every further attempt would burn a full timeout for nothing.
     */
    case Unreachable;
}
