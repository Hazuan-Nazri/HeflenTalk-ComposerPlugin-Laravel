<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connect key
    |--------------------------------------------------------------------------
    |
    | The shared secret issued by HeflenTalk (the tenant's Connect secret).
    | Used to verify the HMAC signature on every incoming request. Without it,
    | the plugin endpoint rejects all requests.
    |
    */

    'api_key' => env('HELFENTALK_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Route prefix
    |--------------------------------------------------------------------------
    |
    | The plugin endpoint is published at "{prefix}/connect". Give HeflenTalk
    | the full URL, e.g. https://your-app.com/helfentalk/connect.
    |
    */

    'route_prefix' => env('HELFENTALK_ROUTE_PREFIX', 'helfentalk'),

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Which connection to query. Null uses the app's default connection.
    |
    */

    'connection' => env('HELFENTALK_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Allowed tables (whitelist)
    |--------------------------------------------------------------------------
    |
    | The ONLY tables the plugin will ever read. Anything outside this list is
    | never queried. Schema (columns) is auto-discovered for these tables.
    |
    */

    'allowed_tables' => [
        // 'employees',
        // 'leaves',
        // 'payslips',
    ],

    /*
    |--------------------------------------------------------------------------
    | Role rules
    |--------------------------------------------------------------------------
    |
    | Maps a user's role (from the verified user context) to a data scope.
    | own  -> only rows where user_column = user_id
    | team -> only rows where team_column = team_id
    | all  -> no row restriction
    |
    */

    'role_rules' => [
        'employee' => ['own_data_only' => true],
        'manager' => ['scope' => 'team'],
        'admin' => ['scope' => 'all'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoping columns
    |--------------------------------------------------------------------------
    */

    'user_column' => env('HELFENTALK_USER_COLUMN', 'user_id'),
    'team_column' => env('HELFENTALK_TEAM_COLUMN', 'team_id'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_rows' => (int) env('HELFENTALK_MAX_ROWS', 25),
    'signature_tolerance' => (int) env('HELFENTALK_SIGNATURE_TOLERANCE', 300),

];
