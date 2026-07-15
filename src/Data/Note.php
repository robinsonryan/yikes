<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Data;

use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Immutable representation of one yikes note file: YAML frontmatter
 * (id/meta/context) + markdown body (the user's note text).
 *
 * The file format round-trips: Note::fromFileContents($note->toFileContents())
 * yields an equivalent instance.
 */
final readonly class Note
{
    /**
     * @param  array{name: string, email: string}  $createdBy
     * @param  array<string, mixed>  $context  loosely-validated page context (url, route, page, account, department, dark_mode, viewport, user_agent)
     * @param  list<string>  $screenshots  relative paths under the yikes root (e.g. "screenshots/<id>/001-....png")
     * @param  array{commit: string|null, note: string|null, completed_at: string|null}|null  $resolution  written by the process-yikes skill when status flips to done
     */
    public function __construct(
        public string $id,
        public ?string $title,
        public NoteType $type,
        public NoteStatus $status,
        public CarbonImmutable $createdAt,
        public array $createdBy,
        public array $context,
        public ?string $stateFile,
        public array $screenshots,
        public ?array $resolution,
        public string $body,
    ) {}

    /**
     * Parse a note file (frontmatter + body) back into a Note.
     */
    public static function fromFileContents(string $contents): self
    {
        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $contents, $matches) !== 1) {
            throw new InvalidArgumentException('Note file is missing YAML frontmatter.');
        }

        /** @var array<string, mixed> $front */
        $front = Yaml::parse($matches[1]);

        $id = $front['id'] ?? null;
        $type = $front['type'] ?? null;
        $status = $front['status'] ?? null;
        $createdAt = $front['created_at'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($status) || ! is_string($createdAt)) {
            throw new InvalidArgumentException('Note frontmatter is missing required fields (id, type, status, created_at).');
        }

        /** @var array{name: string, email: string} $createdBy */
        $createdBy = is_array($front['created_by'] ?? null) ? $front['created_by'] : ['name' => '', 'email' => ''];

        /** @var array<string, mixed> $context */
        $context = is_array($front['context'] ?? null) ? $front['context'] : [];

        /** @var list<string> $screenshots */
        $screenshots = is_array($front['screenshots'] ?? null) ? array_values($front['screenshots']) : [];

        /** @var array{commit: string|null, note: string|null, completed_at: string|null}|null $resolution */
        $resolution = is_array($front['resolution'] ?? null) ? $front['resolution'] : null;

        $stateFile = $front['state_file'] ?? null;
        $title = $front['title'] ?? null;

        return new self(
            id: $id,
            title: is_string($title) ? $title : null,
            type: NoteType::from($type),
            status: NoteStatus::from($status),
            createdAt: CarbonImmutable::parse($createdAt),
            createdBy: $createdBy,
            context: $context,
            stateFile: is_string($stateFile) ? $stateFile : null,
            screenshots: $screenshots,
            resolution: $resolution,
            body: trim($matches[2], "\r\n"),
        );
    }

    /**
     * Serialize to the on-disk file format (frontmatter + body).
     */
    public function toFileContents(): string
    {
        $frontmatter = Yaml::dump($this->frontmatter(), 6, 2);

        return "---\n" . $frontmatter . "---\n\n" . rtrim($this->body) . "\n";
    }

    public function withStatus(NoteStatus $status): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            type: $this->type,
            status: $status,
            createdAt: $this->createdAt,
            createdBy: $this->createdBy,
            context: $this->context,
            stateFile: $this->stateFile,
            screenshots: $this->screenshots,
            resolution: $this->resolution,
            body: $this->body,
        );
    }

    public function withContent(?string $title, string $body): self
    {
        return new self(
            id: $this->id,
            title: $title,
            type: $this->type,
            status: $this->status,
            createdAt: $this->createdAt,
            createdBy: $this->createdBy,
            context: $this->context,
            stateFile: $this->stateFile,
            screenshots: $this->screenshots,
            resolution: $this->resolution,
            body: $body,
        );
    }

    /**
     * @param  list<string>  $screenshots
     */
    public function withScreenshots(array $screenshots): self
    {
        return new self(
            id: $this->id,
            title: $this->title,
            type: $this->type,
            status: $this->status,
            createdAt: $this->createdAt,
            createdBy: $this->createdBy,
            context: $this->context,
            stateFile: $this->stateFile,
            screenshots: $screenshots,
            resolution: $this->resolution,
            body: $this->body,
        );
    }

    /**
     * The frontmatter shape plus body — used for API/Inertia serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->frontmatter(), 'body' => $this->body];
    }

    /**
     * @return array<string, mixed>
     */
    private function frontmatter(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'created_at' => $this->createdAt->toIso8601String(),
            'created_by' => $this->createdBy,
            'context' => $this->context,
            'state_file' => $this->stateFile,
            'screenshots' => $this->screenshots,
            'resolution' => $this->resolution,
        ];
    }
}
