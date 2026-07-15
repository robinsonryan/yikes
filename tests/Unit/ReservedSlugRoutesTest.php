<?php

declare(strict_types=1);

use RobinsonRyan\Yikes\Tests\ReservedSlugTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(ReservedSlugTestCase::class);

beforeEach(function (): void {
    $this->definitionsPath = sys_get_temp_dir() . '/yikes-reserved-defs-' . uniqid();
    mkdir($this->definitionsPath, 0755, true);

    // A tester whose slug collides with a reserved slug on purpose: the
    // route pattern must refuse the match even when the tester exists.
    file_put_contents($this->definitionsPath . '/testers.yaml', <<<'YAML'
        testers:
          - slug: andrea
            name: Andrea Evans
            credentials:
              - { label: Owner, email: andrea.owner@testaccount.example, password: password }
          - slug: roles
            name: Collides With Reserved
            credentials:
              - { label: Owner, email: roles.owner@testaccount.example, password: password }
        YAML);

    config(['yikes.checklists.path' => $this->definitionsPath]);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->definitionsPath));
});

describe('reserved checklist slugs', function (): void {
    it('excludes reserved slugs from the tester catch-all pattern', function (): void {
        $pattern = Route::getRoutes()->getByName('yikes.testing.tester')?->wheres['tester'] ?? null;

        expect($pattern)->toStartWith('(?!(?:roles)$)');
    });

    it('does not match a reserved slug even when a tester by that name exists', function (): void {
        $this->get('/testing/roles')->assertNotFound();
    });

    it('still matches ordinary tester slugs', function (): void {
        $route = Route::getRoutes()->match(Request::create('/testing/andrea'));

        expect($route->getName())->toBe('yikes.testing.tester');
    });
});
