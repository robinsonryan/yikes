<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Data;

use InvalidArgumentException;

/**
 * One human tester (from testers.yaml): identity plus the credential list
 * shown on their checklist landing page.
 */
final readonly class Tester
{
    /**
     * @param  list<array{label: string, email: string, password: string, note: string|null, role: string|null}>  $credentials
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $slug = $data['slug'] ?? null;
        $name = $data['name'] ?? null;

        if (! is_string($slug) || ! is_string($name)) {
            throw new InvalidArgumentException('Tester requires string slug and name.');
        }

        $credentials = [];

        foreach (is_array($data['credentials'] ?? null) ? $data['credentials'] : [] as $credential) {
            if (! is_array($credential)) {
                continue;
            }

            $credentials[] = [
                'label' => (string) ($credential['label'] ?? ''),
                'email' => (string) ($credential['email'] ?? ''),
                'password' => (string) ($credential['password'] ?? ''),
                'note' => isset($credential['note']) ? (string) $credential['note'] : null,
                'role' => isset($credential['role']) ? (string) $credential['role'] : null,
            ];
        }

        return new self(slug: $slug, name: $name, credentials: $credentials);
    }

    /**
     * The email used to attribute yikes notes created from failed steps.
     */
    public function primaryEmail(): string
    {
        return $this->credentials[0]['email'] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'credentials' => $this->credentials,
        ];
    }
}
