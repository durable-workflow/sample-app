<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Waterline Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Waterline will be accessible from. If this
    | setting is null, Waterline will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('WATERLINE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Waterline Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Waterline will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('WATERLINE_PATH', 'waterline'),

    /*
    |--------------------------------------------------------------------------
    | Waterline Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Waterline route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Open Access for Standalone Conformance
    |--------------------------------------------------------------------------
    |
    | Waterline is normally protected by the viewWaterline gate. The polyglot
    | conformance compose stack runs without app users, so it can opt in to
    | unauthenticated Waterline access inside its isolated container network.
    |
    */

    'allow_unauthenticated' => env('WATERLINE_ALLOW_UNAUTHENTICATED', false),

    /*
    |--------------------------------------------------------------------------
    | Workflow Engine Source
    |--------------------------------------------------------------------------
    |
    | Waterline can render legacy runs or workflow v2 runs. The polyglot
    | conformance stack pins this to v2 so it observes the standalone server
    | schema used by the public artifacts.
    |
    */

    'engine_source' => env('WATERLINE_ENGINE_SOURCE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Namespace
    |--------------------------------------------------------------------------
    |
    | When set, Waterline scopes run, worker, and operator views to the named
    | workflow namespace.
    |
    */

    'namespace' => env('WATERLINE_NAMESPACE'),
];
