<?php

declare(strict_types=1);

use RobinsonRyan\Yikes\Data\Tester;
use RobinsonRyan\Yikes\Support\ChecklistRepository;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

function repositoryWithTestersYaml(string $yaml): ChecklistRepository
{
    $path = sys_get_temp_dir() . '/yikes-roles-test-' . uniqid();
    mkdir($path);
    file_put_contents($path . '/testers.yaml', $yaml);

    return new ChecklistRepository($path);
}

it('parses roles and the reference url from testers.yaml', function (): void {
    $repository = repositoryWithTestersYaml(<<<'YAML'
        testers: []
        role_reference_url: /testing/roles
        roles:
          - key: owner
            name: Owner
            summary: The principal.
            can:
              - Everything
              - Payments
          - key: bare
            name: Bare
        YAML);

    $roles = $repository->roles();

    expect($roles)->toHaveCount(2)
        ->and($roles[0])->toBe([
            'key' => 'owner',
            'name' => 'Owner',
            'summary' => 'The principal.',
            'can' => ['Everything', 'Payments'],
        ])
        ->and($roles[1]['summary'])->toBeNull()
        ->and($roles[1]['can'])->toBe([])
        ->and($repository->roleReferenceUrl())->toBe('/testing/roles');
});

it('drops malformed role entries and defaults the reference url to null', function (): void {
    $repository = repositoryWithTestersYaml(<<<'YAML'
        testers: []
        roles:
          - name: No key here
          - just-a-string
          - key: ok
            name: OK
        YAML);

    expect($repository->roles())->toHaveCount(1)
        ->and($repository->roles()[0]['key'])->toBe('ok')
        ->and($repository->roleReferenceUrl())->toBeNull();
});

it('returns no roles when testers.yaml is missing', function (): void {
    $repository = new ChecklistRepository(sys_get_temp_dir() . '/yikes-roles-missing-' . uniqid());

    expect($repository->roles())->toBe([])
        ->and($repository->roleReferenceUrl())->toBeNull();
});

it('parses the optional role key on tester credentials', function (): void {
    $tester = Tester::fromArray([
        'slug' => 'andrea',
        'name' => 'Andrea Evans',
        'credentials' => [
            ['label' => 'Owner', 'email' => 'a@example.test', 'password' => 'secret', 'role' => 'owner'],
            ['label' => 'Guest', 'email' => 'g@example.test', 'password' => 'secret'],
        ],
    ]);

    expect($tester->credentials[0]['role'])->toBe('owner')
        ->and($tester->credentials[1]['role'])->toBeNull();
});
