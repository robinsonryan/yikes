<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RobinsonRyan\Yikes\Http\Controllers\AssetsController;
use RobinsonRyan\Yikes\Http\Controllers\ChecklistsController;
use RobinsonRyan\Yikes\Http\Controllers\NotesController;
use RobinsonRyan\Yikes\Http\Controllers\ScreenshotsController;
use RobinsonRyan\Yikes\Http\Middleware\EnsureYikesEnabled;

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
$screenshotFile = '\d{3}-\d{8}-\d{6}\.(?:png|jpg|jpeg|webp)';

// The prebuilt island bundle — stateless on purpose (no session middleware),
// gated like everything else so the files 404 while yikes is disabled.
Route::middleware([EnsureYikesEnabled::class])
    ->prefix((string) config('yikes.route_prefix', 'yikes'))
    ->get('assets/{file}', [AssetsController::class, 'show'])
    ->where('file', '[A-Za-z0-9_.-]+\.(?:js|css|map)')
    ->name('yikes.assets');

Route::middleware([...(array) config('yikes.middleware', ['web']), EnsureYikesEnabled::class])
    ->prefix((string) config('yikes.route_prefix', 'yikes'))
    ->name('yikes.')
    ->group(function () use ($uuid, $screenshotFile): void {
        Route::get('/', [NotesController::class, 'index'])->name('index');

        Route::post('/notes', [NotesController::class, 'store'])->name('notes.store');
        Route::patch('/notes/{note}', [NotesController::class, 'update'])
            ->where('note', $uuid)->name('notes.update');
        Route::patch('/notes/{note}/status', [NotesController::class, 'updateStatus'])
            ->where('note', $uuid)->name('notes.status');
        Route::delete('/notes/completed', [NotesController::class, 'destroyCompleted'])
            ->name('notes.clearCompleted');
        Route::delete('/notes/{note}', [NotesController::class, 'destroy'])
            ->where('note', $uuid)->name('notes.destroy');

        Route::post('/screenshots', [ScreenshotsController::class, 'storePending'])->name('screenshots.store');
        Route::get('/screenshots/pending/{id}', [ScreenshotsController::class, 'showPending'])
            ->where('id', $uuid)->name('screenshots.showPending');
        Route::delete('/screenshots/pending/{id}', [ScreenshotsController::class, 'destroyPending'])
            ->where('id', $uuid)->name('screenshots.destroyPending');
        Route::get('/screenshots/{note}/{file}', [ScreenshotsController::class, 'show'])
            ->where('note', $uuid)->where('file', $screenshotFile)->name('screenshots.show');
    });

// The UAT checklist surface is deliberately guest-reachable (testers read
// their credentials there BEFORE logging in; staging sits behind an access
// proxy) — its middleware stack is configured separately from the notes'.
$slug = '[A-Za-z0-9-]{1,64}';

// Slugs the host app reserves under the checklist prefix (its own routes
// register after these, so the {tester} catch-all must skip them).
$reserved = array_map(
    fn (string $reservedSlug): string => preg_quote($reservedSlug, '/'),
    (array) config('yikes.checklists.reserved_slugs', []),
);
$testerSlug = $reserved === [] ? $slug : '(?!(?:' . implode('|', $reserved) . ')$)' . $slug;

Route::middleware([...(array) config('yikes.checklists.middleware', ['web']), EnsureYikesEnabled::class])
    ->prefix((string) config('yikes.checklists.route_prefix', 'testing'))
    ->name('yikes.testing.')
    ->group(function () use ($slug, $testerSlug): void {
        Route::get('/', [ChecklistsController::class, 'index'])->name('index');
        Route::get('/{tester}', [ChecklistsController::class, 'tester'])
            ->where('tester', $testerSlug)->name('tester');
        Route::get('/{tester}/{suite}', [ChecklistsController::class, 'suite'])
            ->where(['tester' => $testerSlug, 'suite' => $slug])->name('suite');
        Route::get('/{tester}/{suite}/{test}', [ChecklistsController::class, 'test'])
            ->where(['tester' => $testerSlug, 'suite' => $slug, 'test' => $slug])->name('test');
        Route::post('/{tester}/{suite}/{test}/steps', [ChecklistsController::class, 'recordStep'])
            ->where(['tester' => $testerSlug, 'suite' => $slug, 'test' => $slug])->name('step');
        Route::post('/{tester}/{suite}/{test}/reset', [ChecklistsController::class, 'resetTest'])
            ->where(['tester' => $testerSlug, 'suite' => $slug, 'test' => $slug])->name('reset');
    });
