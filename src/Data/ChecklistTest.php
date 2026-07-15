<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Data;

use InvalidArgumentException;

/**
 * One test inside a checklist suite: an ordered list of manual steps a
 * tester walks through, passing or failing each.
 */
final readonly class ChecklistTest
{
    /**
     * @param  list<string>  $steps  ordered step instructions (1-based when addressed by index)
     */
    public function __construct(
        public string $slug,
        public string $title,
        public ?string $goal,
        public array $steps,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $slug = $data['slug'] ?? null;
        $title = $data['title'] ?? null;

        if (! is_string($slug) || ! is_string($title)) {
            throw new InvalidArgumentException('Checklist test requires string slug and title.');
        }

        $steps = $data['steps'] ?? null;

        if (! is_array($steps) || $steps === []) {
            throw new InvalidArgumentException("Checklist test [{$slug}] requires a non-empty steps list.");
        }

        $goal = $data['goal'] ?? null;

        return new self(
            slug: $slug,
            title: $title,
            goal: is_string($goal) ? $goal : null,
            steps: array_values(array_map(static fn (mixed $step): string => (string) $step, $steps)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'goal' => $this->goal,
            'steps' => $this->steps,
        ];
    }
}
