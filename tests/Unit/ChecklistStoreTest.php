<?php

declare(strict_types=1);

use RobinsonRyan\Yikes\Data\Checklist;
use RobinsonRyan\Yikes\Enums\StepStatus;
use RobinsonRyan\Yikes\Support\ChecklistRepository;
use RobinsonRyan\Yikes\Support\ChecklistResultStore;
use RobinsonRyan\Yikes\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->definitionsPath = sys_get_temp_dir() . '/yikes-checklist-defs-' . uniqid();
    mkdir($this->definitionsPath, 0755, true);

    file_put_contents($this->definitionsPath . '/users.yaml', <<<'YAML'
        slug: users
        title: User Management
        tests:
          - { slug: create-user, title: Invite a user, steps: [Log in., Invite., Verify.] }
          - { slug: suspend, title: Suspend a user, steps: [Suspend., Reactivate.] }
        YAML);

    file_put_contents($this->definitionsPath . '/testers.yaml', <<<'YAML'
        testers:
          - slug: andrea
            name: Andrea Evans
            credentials:
              - { label: Owner, email: andrea.owner@testaccount.example, password: password }
        YAML);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->definitionsPath));
});

function checklistRepository(): ChecklistRepository
{
    config(['yikes.checklists.path' => test()->definitionsPath]);

    return resolve(ChecklistRepository::class);
}

function resultStore(): ChecklistResultStore
{
    return resolve(ChecklistResultStore::class);
}

describe('ChecklistRepository', function (): void {
    it('loads suites, excluding testers.yaml', function (): void {
        $all = checklistRepository()->all();

        expect($all)->toHaveCount(1)
            ->and($all->first()?->slug)->toBe('users');
    });

    it('finds suites and testers by slug, rejecting bad slugs', function (): void {
        $repo = checklistRepository();

        expect($repo->find('users')?->title)->toBe('User Management')
            ->and($repo->find('missing'))->toBeNull()
            ->and($repo->find('../etc'))->toBeNull()
            ->and($repo->findTester('andrea')?->name)->toBe('Andrea Evans')
            ->and($repo->findTester('../andrea'))->toBeNull();
    });

    it('returns empty collections when the definitions directory is missing', function (): void {
        $repo = new ChecklistRepository('/nonexistent-path-' . uniqid());

        expect($repo->all())->toBeEmpty()
            ->and($repo->testers())->toBeEmpty();
    });
});

describe('ChecklistResultStore', function (): void {
    it('records steps and reads them back', function (): void {
        $store = resultStore();

        $store->recordStep('andrea', 'users', 'create-user', 1, StepStatus::Pass);
        $store->recordStep('andrea', 'users', 'create-user', 2, StepStatus::Fail, 'Button missing', 'note-id-1');

        $results = $store->results('andrea', 'users');

        expect($results['tests']['create-user']['steps']['1']['status'])->toBe('pass')
            ->and($results['tests']['create-user']['steps']['2']['status'])->toBe('fail')
            ->and($results['tests']['create-user']['steps']['2']['reason'])->toBe('Button missing')
            ->and($results['tests']['create-user']['steps']['2']['note_id'])->toBe('note-id-1')
            ->and($results['updated_at'])->not->toBeNull();
    });

    it('summarizes per-test and suite status', function (): void {
        $store = resultStore();
        $checklist = checklistRepository()->find('users');
        assert($checklist instanceof Checklist);

        // Nothing recorded → pending.
        expect($store->summarize($checklist, 'andrea')['status'])->toBe('pending');

        // Part of one test → in-progress.
        $store->recordStep('andrea', 'users', 'create-user', 1, StepStatus::Pass);
        $summary = $store->summarize($checklist, 'andrea');
        expect($summary['status'])->toBe('in-progress')
            ->and($summary['tests']['create-user']['status'])->toBe('in-progress');

        // A fail anywhere → failed.
        $store->recordStep('andrea', 'users', 'suspend', 1, StepStatus::Fail, 'Broken');
        expect($store->summarize($checklist, 'andrea')['status'])->toBe('failed');

        // Everything passed → passed.
        $store->recordStep('andrea', 'users', 'create-user', 2, StepStatus::Pass);
        $store->recordStep('andrea', 'users', 'create-user', 3, StepStatus::Pass);
        $store->recordStep('andrea', 'users', 'suspend', 1, StepStatus::Pass);
        $store->recordStep('andrea', 'users', 'suspend', 2, StepStatus::Pass);

        $summary = $store->summarize($checklist, 'andrea');
        expect($summary['status'])->toBe('passed')
            ->and($summary['tests']['create-user'])->toBe(['status' => 'passed', 'passed' => 3, 'failed' => 0, 'total' => 3]);
    });

    it('resets a single test', function (): void {
        $store = resultStore();

        $store->recordStep('andrea', 'users', 'create-user', 1, StepStatus::Pass);
        $store->recordStep('andrea', 'users', 'suspend', 1, StepStatus::Pass);

        $store->resetTest('andrea', 'users', 'create-user');

        $results = $store->results('andrea', 'users');
        expect($results['tests'])->not->toHaveKey('create-user')
            ->and($results['tests'])->toHaveKey('suspend');
    });

    it('rejects traversal-shaped slugs', function (): void {
        resultStore()->recordStep('../evil', 'users', 'create-user', 1, StepStatus::Pass);
    })->throws(InvalidArgumentException::class);
});
