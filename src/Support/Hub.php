<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

/**
 * Mode switch for the dual-mode package.
 *
 * Hub mode is exactly "a hub URL is configured" — no second flag to drift
 * out of sync. Everything hub-related (push-on-capture, flush, disabling
 * the local index UI) keys off this single predicate.
 */
final class Hub
{
    /** The hub's hard per-file screenshot cap (it 413s above this). */
    public const int MAX_SCREENSHOT_BYTES = 5 * 1024 * 1024;

    public static function enabled(): bool
    {
        return self::url() !== '';
    }

    public static function url(): string
    {
        return rtrim((string) config('yikes.hub.url', ''), '/');
    }

    public static function token(): string
    {
        return (string) config('yikes.hub.token', '');
    }

    public static function project(): string
    {
        return (string) config('yikes.hub.project', '');
    }

    public static function timeout(): int
    {
        return max(1, (int) config('yikes.hub.timeout', 3));
    }
}
