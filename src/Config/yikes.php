<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the yikes note-capture surface. When false the routes
    | respond 404 (via the EnsureYikesEnabled middleware) and the app hides
    | the FAB. Default OFF — dev/staging opt in via env; never enable on prod.
    |
    */
    'enabled' => (bool) env('YIKES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Storage path
    |--------------------------------------------------------------------------
    |
    | Root directory for the flat-file note store. Committed to the repo on
    | purpose (Claude processes the approved notes later), so it defaults to
    | the project root — NOT storage/.
    |
    */
    'path' => env('YIKES_PATH', base_path('.yikes')),

    /*
    |--------------------------------------------------------------------------
    | Hub (push-on-capture)
    |--------------------------------------------------------------------------
    |
    | With a hub URL set, yikes runs in HUB MODE: captured notes are written
    | to the local .yikes/ store first (capture never blocks on the hub),
    | then pushed to the hub over its ingest API. Unpushed bundles are
    | retried on the next capture, via `php artisan yikes:flush`, and via
    | the scheduler when the host app runs one. The local index/triage UI
    | is disabled — the hub owns triage; the FAB capture surface remains.
    |
    | Empty URL (the default) = LOCAL MODE: exactly the flat-file behavior
    | this package has always had, index UI included.
    |
    */
    'hub' => [
        'url' => env('YIKES_HUB_URL', ''),
        'token' => env('YIKES_HUB_TOKEN', ''),
        'project' => env('YIKES_PROJECT', ''),

        // Connect/read timeout (seconds) for the synchronous push attempted
        // during capture. Deliberately short: a slow hub must never make the
        // capture UX feel broken — the bundle just stays queued.
        'timeout' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Guest-reachable by design (like the checklists): QC testers file notes
    | from auth/consumer pages before logging in, and the surface only exists
    | where YIKES_ENABLED is on (dev/staging behind an access proxy).
    |
    */
    'middleware' => ['web'],
    'route_prefix' => 'yikes',

    /*
    |--------------------------------------------------------------------------
    | UI delivery
    |--------------------------------------------------------------------------
    |
    | The package ships a prebuilt, self-contained Vue island (dist/). With
    | auto_inject on, a global middleware injects the bootstrap script +
    | module tag into every HTML response while yikes is enabled — no host
    | build integration needed. Off: place the snippet yourself by rendering
    | RobinsonRyan\Yikes\Support\YikesAssets::injectHtml($request).
    |
    | dark_selector: a CSS selector matched against <html>/<body> to detect
    | the host's dark mode (e.g. '.app-dark' or '.dark'). Null = the island
    | watches common conventions ('.dark'/'.app-dark' on <html>) and falls
    | back to prefers-color-scheme.
    |
    */
    'ui' => [
        'auto_inject' => (bool) env('YIKES_AUTO_INJECT', true),
        'dark_selector' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | CSP nonce
    |--------------------------------------------------------------------------
    |
    | For hosts running a nonce-based Content-Security-Policy: the package
    | stamps a per-request nonce on every inline <script>/<style> it emits
    | (the auto-injected bootstrap AND its own Blade shell).
    |
    | null (default): auto-detect Laravel's Vite nonce — when the host app
    | calls Vite::useCspNonce(), that nonce is reused with zero config.
    | Otherwise set a resolver: a callable returning ?string, or an
    | invokable class-string (config:cache safe). A configured resolver is
    | authoritative — its null return means "no nonce" and skips the Vite
    | auto-detection, so `fn () => null` hard-disables it.
    |
    | No nonce resolved = output byte-identical to a non-CSP install.
    |
    */
    'csp_nonce' => null,

    /*
    |--------------------------------------------------------------------------
    | Capture limits
    |--------------------------------------------------------------------------
    |
    | capture_state toggles persisting the Pinia snapshot; max_state_kb caps
    | the accepted snapshot payload and max_screenshot_kb caps each uploaded
    | screenshot (validation `max:` is expressed in kilobytes).
    |
    */
    'capture_state' => true,
    'max_screenshot_kb' => 4096,
    'max_state_kb' => 256,

    /*
    |--------------------------------------------------------------------------
    | UAT checklists
    |--------------------------------------------------------------------------
    |
    | Definitions (suite YAML files + testers.yaml) are authored content
    | versioned with the app — kept OUTSIDE the yikes store so a persistent
    | `.yikes/` volume never shadows freshly-deployed definitions. Results
    | are written under the yikes store (`<yikes-path>/checklists/`).
    | The checklist routes default to guest-reachable (`web` only): testers
    | read their login credentials there before authenticating, and the
    | whole surface already sits behind the yikes enabled gate.
    |
    */
    'checklists' => [
        'path' => env('YIKES_CHECKLISTS_PATH', resource_path('checklists')),
        'middleware' => ['web'],
        'route_prefix' => 'testing',

        /*
        | URL template for one-click credential auto-login links on the tester
        | landing page; `{email}` is replaced with the url-encoded credential
        | email (e.g. `/dev/login-as?email={email}`). Null = plain-text emails.
        | The host app owns the endpoint — the package only renders the link.
        */
        'login_url' => null,

        /*
        | First-segment slugs under the checklist prefix that the package's
        | `/{tester}` catch-all must NOT match, so the host app can mount its
        | own pages (e.g. a reference page) under the same prefix. Package
        | routes register before app routes, so without a reservation the
        | catch-all shadows them.
        */
        'reserved_slugs' => [],
    ],

];
