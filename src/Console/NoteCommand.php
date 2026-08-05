<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Support\Hub;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Support\PushQueue;

/**
 * CLI capture for coding agents: file a yikes note without the browser
 * island. The note takes the exact same path as a FAB capture — flat-file
 * store first, then (hub mode) a synchronous best-effort push — so agent
 * notes queue, retry, and triage identically to human ones. Every note is
 * stamped `source: agent` in context so the hub can filter them.
 */
final class NoteCommand extends Command
{
    protected $signature = 'yikes:note
        {body : The note body — what needs attention and why it was deferred}
        {--title= : Short title shown in triage lists}
        {--type=bug : Note type: bug, layout, idea or refactor}
        {--context=* : Extra context as key=value (e.g. --context=file=app/Models/User.php:42)}
        {--by=Claude Code : Name recorded as the creator}';

    protected $description = 'File a yikes note from the CLI (agent capture)';

    public function handle(NoteRepository $notes): int
    {
        if (! (bool) config('yikes.enabled', false)) {
            $this->error('Yikes is disabled (YIKES_ENABLED is off) — refusing to file a note.');

            return self::FAILURE;
        }

        $type = NoteType::tryFrom((string) $this->option('type'));

        if ($type === null) {
            $this->error(sprintf(
                'Unknown type "%s". Valid types: %s.',
                (string) $this->option('type'),
                implode(', ', NoteType::values()),
            ));

            return self::FAILURE;
        }

        $context = ['source' => 'agent'];

        /** @var list<string> $entries */
        $entries = (array) $this->option('context');

        foreach ($entries as $entry) {
            [$key, $value] = array_pad(explode('=', $entry, 2), 2, null);

            if ($key === null || $key === '' || $value === null) {
                $this->error(sprintf('Context entry "%s" is not key=value.', $entry));

                return self::FAILURE;
            }

            $context[$key] = $value;
        }

        $title = $this->option('title');

        $note = $notes->create(
            body: (string) $this->argument('body'),
            title: is_string($title) && $title !== '' ? $title : null,
            type: $type,
            context: $context,
            createdBy: ['name' => (string) $this->option('by'), 'email' => ''],
            stateJson: null,
        );

        $this->info(sprintf('Yikes! Note %s saved.', $note->id));

        // Same contract as capture over HTTP: the note is safely on disk,
        // the push is best-effort and a hub problem must never fail the
        // command — the bundle just stays queued for the next flush.
        if (Hub::enabled()) {
            try {
                $result = $this->laravel->make(PushQueue::class)->flush();

                $this->info(sprintf('Pushed %d, still queued %d.', $result['pushed'], $result['queued']));
            } catch (\Throwable $e) {
                Log::warning('yikes: push-on-capture failed; bundle stays queued.', [
                    'error' => $e->getMessage(),
                ]);
                $this->warn('Hub push failed — the note stays queued for the next flush.');
            }
        }

        return self::SUCCESS;
    }
}
