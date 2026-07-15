<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Yikes\Http\Middleware\InjectYikesAssets;
use RobinsonRyan\Yikes\Support\ChecklistRepository;
use RobinsonRyan\Yikes\Support\ChecklistResultStore;
use RobinsonRyan\Yikes\Support\Hub;
use RobinsonRyan\Yikes\Support\HubClient;
use RobinsonRyan\Yikes\Support\NoteRepository;
use RobinsonRyan\Yikes\Support\PushQueue;

class YikesServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/yikes.php',
            'yikes',
        );

        // Bound (not singleton) so the base path always reflects the current
        // `yikes.path` config — tests repoint it at temp dirs per-test.
        $this->app->bind(NoteRepository::class, function (Application $app): NoteRepository {
            /** @var string $path */
            $path = $app->make('config')->get('yikes.path');

            return new NoteRepository($path);
        });

        $this->app->bind(ChecklistRepository::class, function (Application $app): ChecklistRepository {
            /** @var string $path */
            $path = $app->make('config')->get('yikes.checklists.path');

            return new ChecklistRepository($path);
        });

        $this->app->bind(ChecklistResultStore::class, function (Application $app): ChecklistResultStore {
            /** @var string $path */
            $path = $app->make('config')->get('yikes.path');

            return new ChecklistResultStore($path);
        });

        // Hub-mode collaborators — bound (not singleton) like the repository
        // so they always reflect the current hub config (tests flip it
        // per-test; local mode simply never resolves them).
        $this->app->bind(HubClient::class, fn (): HubClient => new HubClient(
            Hub::url(),
            Hub::token(),
            Hub::timeout(),
        ));

        $this->app->bind(PushQueue::class, fn (Application $app): PushQueue => new PushQueue(
            $app->make(NoteRepository::class),
            $app->make(HubClient::class),
        ));
    }

    /**
     * Bootstrap any application services.
     *
     * Routes are registered unconditionally; the EnsureYikesEnabled
     * middleware answers 404 for the whole surface when `yikes.enabled`
     * is false (keeps the toggle a runtime config concern, testable
     * without re-booting the application).
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/Config/yikes.php' => config_path('yikes.php'),
        ], 'yikes-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'yikes');

        // Auto-inject the island bootstrap into host HTML responses. Pushed
        // as GLOBAL middleware (not onto a group) so it works in any host —
        // Statamic, Breeze, custom groups — and it is registered
        // unconditionally (like the routes): it no-ops while yikes is
        // disabled, keeping enable/disable a plain runtime config concern.
        if ((bool) config('yikes.ui.auto_inject', true)) {
            $kernel = $this->app->make(Kernel::class);

            if ($kernel instanceof HttpKernel) {
                $kernel->pushMiddleware(InjectYikesAssets::class);
            }
        }
    }
}
