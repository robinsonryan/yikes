<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use RobinsonRyan\Yikes\Data\Checklist;
use RobinsonRyan\Yikes\Data\Tester;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/**
 * Read-only loader for checklist definitions and testers.
 *
 * Definitions are authored YAML files versioned with the app (config
 * `yikes.checklists.path`) — deliberately OUTSIDE the yikes store so a
 * persistent `.yikes/` volume on staging never shadows freshly-deployed
 * definitions. `testers.yaml` in the same directory declares who tests
 * and which credentials their landing page displays.
 */
final class ChecklistRepository
{
    private const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/D';

    public function __construct(private readonly string $definitionsPath) {}

    /**
     * All suite definitions, ordered by filename.
     *
     * @return Collection<int, Checklist>
     */
    public function all(): Collection
    {
        $files = glob($this->definitionsPath . '/*.yaml') ?: [];

        return collect($files)
            ->reject(static fn (string $file): bool => basename($file) === 'testers.yaml')
            ->sort()
            ->map(function (string $file): ?Checklist {
                $contents = file_get_contents($file);

                try {
                    return $contents === false ? null : Checklist::fromYaml($contents);
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter(static fn (?Checklist $checklist): bool => $checklist instanceof Checklist)
            ->values();
    }

    public function find(string $slug): ?Checklist
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        return $this->all()->first(
            static fn (Checklist $checklist): bool => $checklist->slug === $slug,
        );
    }

    /**
     * @return Collection<int, Tester>
     */
    public function testers(): Collection
    {
        $data = $this->testersFile();

        return collect(is_array($data['testers'] ?? null) ? $data['testers'] : [])
            ->map(function (mixed $tester): ?Tester {
                try {
                    return Tester::fromArray(is_array($tester) ? $tester : []);
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter(static fn (?Tester $tester): bool => $tester instanceof Tester)
            ->values();
    }

    public function findTester(string $slug): ?Tester
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        return $this->testers()->first(
            static fn (Tester $tester): bool => $tester->slug === $slug,
        );
    }

    /**
     * Role capability summaries (`roles:` in testers.yaml) — the data behind
     * the per-credential role popovers on the tester landing page. Credentials
     * opt in by naming a role `key`. Optional; missing or malformed entries
     * are dropped.
     *
     * @return list<array{key: string, name: string, summary: string|null, can: list<string>}>
     */
    public function roles(): array
    {
        $data = $this->testersFile();

        $roles = [];

        foreach (is_array($data['roles'] ?? null) ? $data['roles'] : [] as $role) {
            if (! is_array($role) || ! is_string($role['key'] ?? null) || ! is_string($role['name'] ?? null)) {
                continue;
            }

            $roles[] = [
                'key' => $role['key'],
                'name' => $role['name'],
                'summary' => isset($role['summary']) ? (string) $role['summary'] : null,
                'can' => array_values(array_map(
                    static fn (mixed $item): string => (string) $item,
                    array_filter(is_array($role['can'] ?? null) ? $role['can'] : [], 'is_scalar'),
                )),
            ];
        }

        return $roles;
    }

    /**
     * Optional link to the host app's full roles reference page
     * (`role_reference_url:` in testers.yaml), shown in the role popovers.
     */
    public function roleReferenceUrl(): ?string
    {
        $url = $this->testersFile()['role_reference_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Parsed testers.yaml, or [] when missing/unreadable/invalid.
     *
     * @return array<string, mixed>
     */
    private function testersFile(): array
    {
        $file = $this->definitionsPath . '/testers.yaml';

        if (! is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        try {
            $data = Yaml::parse($contents);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
