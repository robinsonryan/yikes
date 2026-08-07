<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RobinsonRyan\Yikes\YikesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $yikesPath = '';

    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            YikesServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $this->yikesPath = sys_get_temp_dir().'/yikes-package-tests-'.uniqid();

        $app['config']->set('yikes.path', $this->yikesPath);
        // The feature suite exercises the full `web` group (encrypted cookies).
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->yikesPath !== '' && is_dir($this->yikesPath)) {
            $this->removeDirectory($this->yikesPath);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        $files = scandir($directory) ?: [];

        foreach ($files as $file) {
            if ($file === '.') {
                continue;
            }
            if ($file === '..') {
                continue;
            }
            $path = $directory.'/'.$file;

            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
