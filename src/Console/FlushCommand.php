<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Console;

use Illuminate\Console\Command;
use RobinsonRyan\Yikes\Support\Hub;
use RobinsonRyan\Yikes\Support\PushQueue;

/**
 * Replays the hub-mode offline queue: every captured-but-unpushed bundle
 * is pushed again. Safe to run any time — hub ingest is idempotent by note
 * id, so double-flushing (capture + scheduler + a manual run) costs nothing.
 */
final class FlushCommand extends Command
{
    protected $signature = 'yikes:flush';

    protected $description = 'Push queued yikes note bundles to the hub';

    public function handle(): int
    {
        if (! Hub::enabled()) {
            $this->info('Yikes is in local mode (no YIKES_HUB_URL) — nothing to flush.');

            return self::SUCCESS;
        }

        $result = $this->laravel->make(PushQueue::class)->flush();

        $this->info(sprintf(
            'Pushed %d, still queued %d, conflicts %d.',
            $result['pushed'],
            $result['queued'],
            $result['conflicts'],
        ));

        if ($result['conflicts'] > 0) {
            $this->warn('Conflicted bundles carry a push/<id>.conflict marker and will not be retried.');
        }

        // Remaining queued bundles are retry-later by design (hub down or
        // throttled) — not a failure of this command.
        return self::SUCCESS;
    }
}
