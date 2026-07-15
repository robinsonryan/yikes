<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\Vite;
use InvalidArgumentException;

/**
 * Resolves the per-request CSP nonce to stamp on every inline <script> and
 * <style> the package emits, so nonce-based `script-src`/`style-src`
 * policies don't block the island without any consumer-side rewriting.
 *
 * Resolution order:
 *  1. `yikes.csp_nonce` — a callable or invokable class-string resolver.
 *     When configured, its return value is authoritative: a non-empty
 *     string is used as-is, anything else means "no nonce" (auto-detection
 *     is NOT consulted, so `fn () => null` hard-disables it).
 *  2. Auto-detection — Laravel's Vite nonce (`Vite::cspNonce()`), non-null
 *     only when the host app opted in via `Vite::useCspNonce()`.
 *
 * No nonce resolved = the emitted HTML is byte-identical to a package
 * without CSP support.
 */
final class CspNonce
{
    public static function resolve(): ?string
    {
        $resolver = config('yikes.csp_nonce');

        if ($resolver !== null) {
            if (is_string($resolver) && class_exists($resolver)) {
                $resolver = app($resolver);
            }

            if (! is_callable($resolver)) {
                throw new InvalidArgumentException(
                    'yikes.csp_nonce must be null, a callable, or an invokable class-string.',
                );
            }

            return self::normalize($resolver());
        }

        if (! class_exists(FoundationVite::class)) {
            return null;
        }

        return self::normalize(Vite::cspNonce());
    }

    /**
     * The ready-to-concatenate attribute: ` nonce="…"` or `''` when no
     * nonce is available, keeping no-CSP output byte-identical.
     */
    public static function attr(): string
    {
        $nonce = self::resolve();

        return $nonce === null
            ? ''
            : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }

    private static function normalize(mixed $nonce): ?string
    {
        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
