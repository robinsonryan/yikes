<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Tests;

/**
 * Boots the package with `yikes.checklists.reserved_slugs` populated.
 *
 * Reserved slugs are compiled into the checklist route patterns at
 * registration time, so — unlike the runtime `yikes.enabled` gate — they
 * cannot be toggled with config() inside a test; the environment must
 * carry them before the service provider boots.
 */
abstract class ReservedSlugTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('yikes.enabled', true);
        $app['config']->set('yikes.checklists.reserved_slugs', ['roles']);
    }
}
