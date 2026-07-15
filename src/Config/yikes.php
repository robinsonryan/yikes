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
