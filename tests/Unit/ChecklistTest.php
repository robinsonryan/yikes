<?php

declare(strict_types=1);

use RobinsonRyan\Yikes\Data\Checklist;
use RobinsonRyan\Yikes\Data\Tester;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

it('parses a suite definition from yaml', function (): void {
    $checklist = Checklist::fromYaml(<<<'YAML'
        slug: users
        title: User Management
        description: Users page flows.
        tests:
          - slug: create-user
            title: Invite a new user
            goal: Invites work.
            steps:
              - Log in.
              - Invite someone.
        YAML);

    expect($checklist->slug)->toBe('users')
        ->and($checklist->title)->toBe('User Management')
        ->and($checklist->description)->toBe('Users page flows.')
        ->and($checklist->tests)->toHaveCount(1)
        ->and($checklist->tests[0]->slug)->toBe('create-user')
        ->and($checklist->tests[0]->goal)->toBe('Invites work.')
        ->and($checklist->tests[0]->steps)->toBe(['Log in.', 'Invite someone.']);
});

it('rejects a suite without tests', function (): void {
    Checklist::fromYaml("slug: empty\ntitle: Empty\ntests: []");
})->throws(InvalidArgumentException::class);

it('rejects a test without steps', function (): void {
    Checklist::fromYaml(<<<'YAML'
        slug: users
        title: Users
        tests:
          - slug: broken
            title: Broken
            steps: []
        YAML);
})->throws(InvalidArgumentException::class);

it('finds tests by slug', function (): void {
    $checklist = Checklist::fromYaml(<<<'YAML'
        slug: users
        title: Users
        tests:
          - { slug: a, title: A, steps: [one] }
          - { slug: b, title: B, steps: [one, two] }
        YAML);

    expect($checklist->findTest('b')?->title)->toBe('B')
        ->and($checklist->findTest('missing'))->toBeNull();
});

it('builds a tester from array data with credential defaults', function (): void {
    $tester = Tester::fromArray([
        'slug' => 'andrea',
        'name' => 'Andrea Evans',
        'credentials' => [
            ['label' => 'Owner', 'email' => 'a@example.test', 'password' => 'secret'],
        ],
    ]);

    expect($tester->slug)->toBe('andrea')
        ->and($tester->credentials[0]['note'])->toBeNull()
        ->and($tester->primaryEmail())->toBe('a@example.test');
});
