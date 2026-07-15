<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Data;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * A checklist suite definition (one YAML file): an ordered set of manual
 * tests for one functional area (users, billing, ...). Definitions are
 * authored content versioned with the app — results live separately under
 * the yikes store.
 */
final readonly class Checklist
{
    /**
     * @param  list<ChecklistTest>  $tests
     */
    public function __construct(
        public string $slug,
        public string $title,
        public ?string $description,
        public array $tests,
    ) {}

    public static function fromYaml(string $yaml): self
    {
        /** @var array<string, mixed> $data */
        $data = Yaml::parse($yaml);

        $slug = $data['slug'] ?? null;
        $title = $data['title'] ?? null;

        if (! is_string($slug) || ! is_string($title)) {
            throw new InvalidArgumentException('Checklist requires string slug and title.');
        }

        $tests = $data['tests'] ?? null;

        if (! is_array($tests) || $tests === []) {
            throw new InvalidArgumentException("Checklist [{$slug}] requires a non-empty tests list.");
        }

        $description = $data['description'] ?? null;

        return new self(
            slug: $slug,
            title: $title,
            description: is_string($description) ? $description : null,
            tests: array_values(array_map(
                static fn (mixed $test): ChecklistTest => ChecklistTest::fromArray(is_array($test) ? $test : []),
                $tests,
            )),
        );
    }

    public function findTest(string $slug): ?ChecklistTest
    {
        foreach ($this->tests as $test) {
            if ($test->slug === $slug) {
                return $test;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'tests' => array_map(static fn (ChecklistTest $test): array => $test->toArray(), $this->tests),
        ];
    }
}
