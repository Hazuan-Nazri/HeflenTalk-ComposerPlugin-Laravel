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
    | The ONLY tables the plugin will ever touch. Anything outside this list is
    | never queried or written. Schema (columns) is auto-discovered.
    |
    | Use the string 'all' to allow every table in the database, or list the
    | specific tables you want to expose:
    |
    |   'allowed_tables' => 'all',
    |   'allowed_tables' => ['employees', 'leaves', 'payslips'],
    |
    */

    'allowed_tables' => [
        // 'employees',
        // 'leaves',
        // 'payslips',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models (required for WRITE actions)
    |--------------------------------------------------------------------------
    |
    | Maps a table to its Eloquent model. Writes (create/edit/delete) go THROUGH
    | the model so your own business logic runs — SoftDeletes, observers, model
    | events, validation and approval workflows all fire. The chatbot can never
    | bypass them. A table without a model mapping is READ-ONLY, even if a role
    | is granted write capability below.
    |
    |   'models' => [
    |       'workers' => \App\Models\Worker::class,
    |       'epasses' => \App\Models\Epass::class,
    |   ],
    |
    */

    'models' => [
        // 'workers' => \App\Models\Worker::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Capabilities (per-role × per-table)
    |--------------------------------------------------------------------------
    |
    | What the chatbot may do on each table, for each user role. 'view' is the
    | base read access; 'create', 'edit' and 'delete' layer on top (granting any
    | write implies 'view'). Use the 'all' table key to apply a capability set to
    | every allowed table; a specific table key overrides 'all' for that table.
    |
    |   'capabilities' => [
    |       'admin'    => ['all' => ['view', 'create', 'edit', 'delete']],
    |       'manager'  => ['workers' => ['view', 'edit'], 'all' => ['view']],
    |       'employee' => ['all' => ['view']],
    |   ],
    |
    | An empty matrix means VIEW-ONLY for everyone (backward compatible).
    |
    */

    'capabilities' => [
        // 'admin' => ['all' => ['view', 'create', 'edit', 'delete']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Writable columns
    |--------------------------------------------------------------------------
    |
    | Columns the chatbot is allowed to set on create/edit, per table. Anything
    | not listed here is never written. Default (empty) blocks all writes for
    | the table until you explicitly opt columns in.
    |
    |   'writable_columns' => [
    |       'workers' => ['status', 'notes'],
    |   ],
    |
    */

    'writable_columns' => [
        // 'workers' => ['status', 'notes'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guarded columns (never writable)
    |--------------------------------------------------------------------------
    |
    | A global denylist applied on top of writable_columns — these can never be
    | set by the chatbot regardless of configuration.
    |
    */

    'guarded_columns' => [
        'id', 'uuid', 'created_at', 'updated_at', 'deleted_at',
        'password', 'remember_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Write safety
    |--------------------------------------------------------------------------
    |
    | max_write_rows: the most rows a single update/delete may affect. Protects
    | against sweeping operations (e.g. "delete all workers"). Default 1.
    |
    */

    'max_write_rows' => (int) env('HELFENTALK_MAX_WRITE_ROWS', 1),

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Every write (and its preview) is recorded for accountability. Publish and
    | run the migration:  php artisan vendor:publish --tag=helfentalk-migrations
    |
    */

    'audit' => [
        'enabled' => (bool) env('HELFENTALK_AUDIT_ENABLED', true),
        'table' => env('HELFENTALK_AUDIT_TABLE', 'helfentalk_audit_logs'),
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
