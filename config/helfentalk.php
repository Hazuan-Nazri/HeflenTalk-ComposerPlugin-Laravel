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
    | Actions menu (run your OWN controllers)
    |--------------------------------------------------------------------------
    |
    | The recommended way to let the chatbot DO things. Each entry maps a plain
    | English label to one of YOUR controller methods. The chatbot can only ever
    | perform actions listed here, and every action runs THROUGH your controller,
    | so your validation, authorization (policies/gates), if/else logic and
    | approval flows all execute exactly as they do for a normal web request —
    | the bot can never bypass them.
    |
    | Each action:
    |   'label'      => what the action does, in plain words (the AI reads this to
    |                   decide which action a user's request maps to). Be specific:
    |                   "Change a worker's status" beats "edit worker".
    |   'controller' => [Controller::class, 'method'] that PERFORMS the action and
    |                   RETURNS data/JSON (use update/store/destroy — NOT the
    |                   form-rendering edit/create/show methods).
    |   'inputs'     => field name => description. Use your REAL field/column names
    |                   (the names your controller + validation expect), so the AI
    |                   sends data your controller actually accepts. The bot shows
    |                   these to the user and fills them from the conversation.
    |   'confirm'    => true (default) previews the change and asks the user to
    |                   confirm before the controller runs; false runs immediately.
    |   'roles'      => optional allow-list of roles that may use this action
    |                   (default: any authenticated user — your controller's own
    |                   policy is still the real gate).
    |   'bindings'   => optional [param => Model::class] for controller methods that
    |                   type-hint a route-model (e.g. update(Request, Worker $worker));
    |                   the matching input is resolved to that model instance.
    |
    | Example:
    |   'actions' => [
    |       'change_worker_status' => [
    |           'label'      => 'Change a worker’s status',
    |           'controller' => [\App\Http\Controllers\WorkerController::class, 'update'],
    |           'inputs'     => [
    |               'worker' => 'The worker ID to update',
    |               'status' => 'New status: "active" or "inactive"',
    |           ],
    |           'confirm'    => true,
    |           'roles'      => ['admin', 'manager'],
    |           'bindings'   => ['worker' => \App\Models\Worker::class],
    |       ],
    |   ],
    |
    */

    'actions' => [

        /*
        | ---------------------------------------------------------------------
        | FULL EXAMPLE — copy one block per action, uncomment, and edit.
        | Each numbered note explains what to put and whether it is required.
        | ---------------------------------------------------------------------
        |
        | The array KEY ('change_worker_status') is the action's internal id.
        | REQUIRED. Lower_snake_case, unique. The user never sees it; it is how
        | the bot refers to this action. Make it descriptive.
        */
        // 'change_worker_status' => [
        //
        //     // (1) label — REQUIRED. Plain-English sentence describing what the
        //     //     action does. The AI reads THIS to decide when to use the
        //     //     action, so be specific. Good: "Change a worker's status".
        //     //     Vague: "edit worker".
        //     'label' => 'Change a worker’s status',
        //
        //     // (2) controller — REQUIRED. The method that actually does the work.
        //     //     MUST be the array form [Controller::class, 'method'] — NOT just
        //     //     the name 'WorkerController'. Point at a method that PERFORMS and
        //     //     RETURNS data (update / store / destroy), NOT the form-rendering
        //     //     edit / create / show (those return an HTML page and write
        //     //     nothing). Accepted formats:
        //     //         [\App\Http\Controllers\WorkerController::class, 'update']  ✅ preferred
        //     //         'App\Http\Controllers\WorkerController@update'             ✅ also works
        //     //         \App\Http\Controllers\WorkerController::class              ✅ if it's an __invoke controller
        //     'controller' => [\App\Http\Controllers\WorkerController::class, 'update'],
        //
        //     // (3) inputs — REQUIRED for actions that take data. Map each input
        //     //     the action needs to a short description for the AI.
        //     //     IMPORTANT: the KEY must be your REAL field/column name — the
        //     //     exact name your controller and its validation expect. If your
        //     //     column is 'employment_state', the key must be 'employment_state'
        //     //     (not 'status'), or your controller never receives the value.
        //     'inputs' => [
        //         // your_real_field_name => 'description shown to / used by the AI',
        //         'worker' => 'The worker ID to change',                 // column / route key
        //         'status' => 'New status: "active" or "inactive"',      // column name
        //     ],
        //
        //     // (4) confirm — OPTIONAL. Default true. true = the bot previews the
        //     //     change and asks the user to confirm before your controller runs
        //     //     (recommended for any write). false = runs immediately (use only
        //     //     for safe/read-like actions, e.g. "look up balance").
        //     'confirm' => true,
        //
        //     // (5) roles — OPTIONAL. Allow-list of user roles (from the verified
        //     //     user context) that may use this action. Omit to allow any
        //     //     signed-in user. Your controller's own Policy/Gate is still the
        //     //     real enforcer — this is just an extra, coarse filter.
        //     'roles' => ['admin', 'manager'],
        //
        //     // (6) bindings — OPTIONAL. Only needed when your controller method
        //     //     type-hints a route-model, e.g. update(Request $r, Worker $worker).
        //     //     Map the PARAMETER name to its Model class; the plugin resolves
        //     //     the matching input (here 'worker') to that model instance and
        //     //     passes it in. If your method takes a plain id (e.g.
        //     //     update(Request $r, int $id)), you don't need this.
        //     'bindings' => ['worker' => \App\Models\Worker::class],
        //
        // ],

        /*
        | A SECOND, MINIMAL example — only the three required keys. Use this shape
        | when your controller method takes plain inputs and needs no role filter
        | or model binding.
        */
        // 'register_new_worker' => [
        //     'label'      => 'Register a new worker',
        //     'controller' => [\App\Http\Controllers\WorkerController::class, 'store'],
        //     'inputs'     => [
        //         'name'       => 'Full name of the new worker',
        //         'department' => 'Department to assign them to',
        //     ],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Acting user (for the actions menu)
    |--------------------------------------------------------------------------
    |
    | To run your controllers as the person chatting, the plugin logs in the user
    | identified by the verified user context, so auth()->user(), policies and
    | gates work normally. Point this at your User model and the column matched
    | against the user context's user_id.
    |
    |   'auth' => [
    |       'model' => \App\Models\User::class,
    |       'key'   => 'id',
    |       'guard' => null,   // null = the app's default guard
    |   ],
    |
    | Leave 'model' null to disable controller actions (the actions menu then
    | does nothing — read context and table CRUD still work).
    |
    */

    'auth' => [

        // model — REQUIRED to enable the actions menu. Your User model FQCN, e.g.
        //   \App\Models\User::class
        // Leave null to keep controller actions OFF (read context + table CRUD
        // still work). The plugin logs in this user before running a controller,
        // so auth()->user(), gates and policies behave normally.
        'model' => null,

        // key — OPTIONAL (default 'id'). The User column matched against the
        // user-context user_id to find the acting user. Change only if you key
        // users by something other than the primary id (e.g. 'uuid', 'staff_no').
        'key' => env('HELFENTALK_AUTH_KEY', 'id'),

        // guard — OPTIONAL (default null = your app's default guard). Set a guard
        // name only if your users authenticate through a non-default guard.
        'guard' => env('HELFENTALK_AUTH_GUARD'),

    ],

    /*
    |--------------------------------------------------------------------------
    | User-context token endpoint  (GET {prefix}/token)
    |--------------------------------------------------------------------------
    |
    | Lets your chat UI obtain a signed user-context JWT WITHOUT writing any
    | signing code. While a user is logged in, your front-end calls
    | GET /helfentalk/token (guarded by YOUR auth) and receives:
    |
    |   { "token": "<jwt>", "expires_in": 900 }
    |
    | It then sends that token to HeflenTalk's chat API as
    | user_context.token. The JWT is HS256-signed with your Connect secret
    | (HELFENTALK_KEY) and carries: user_id (from auth.key), name, role and
    | (optionally) department — exactly what HeflenTalk verifies.
    |
    | This route is NOT HMAC-protected; it is protected by 'middleware' below,
    | which must authenticate YOUR dashboard user.
    |
    */

    'token' => [

        // enabled — OPTIONAL (default true). Set false to not register the
        // /token route at all (e.g. if you prefer to sign the JWT yourself).
        'enabled' => (bool) env('HELFENTALK_TOKEN_ENABLED', true),

        // middleware — REQUIRED to be correct for YOUR app. The auth middleware
        // that identifies the logged-in user on this route. This is YOUR app's
        // auth, NOT the HMAC used by connect/manifest/action. Common choices:
        //   ['auth:sanctum']  — SPA / token-based dashboards (Sanctum)
        //   ['auth']          — session-based (web guard) dashboards
        'middleware' => ['auth:sanctum'],

        // ttl — OPTIONAL (default 900s = 15 min). How long an issued token is
        // valid. Keep it short; the UI fetches a fresh one when it expires.
        'ttl' => (int) env('HELFENTALK_TOKEN_TTL', 900),

        // role_field — OPTIONAL. The user attribute/column holding the role used
        // by capabilities & role_rules. Leave null to auto-detect: Spatie's
        // getRoleNames()->first(), else a plain 'role' attribute.
        'role_field' => null,

        // name_field — OPTIONAL. The user attribute holding the display name.
        // Leave null to auto-detect 'name' then 'full_name'.
        'name_field' => null,

        // department_field — OPTIONAL. The user attribute holding a department,
        // if you want it in the token. Leave null to omit the department claim.
        'department_field' => null,

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
    | View routes (deep-link a record to its page)
    |--------------------------------------------------------------------------
    |
    | A route template per table. When the chatbot lists records, the plugin
    | attaches a "view_url" to each row so your app's chat UI can make the row
    | clickable — opening that record's page. Placeholders in {braces} are
    | filled from the row's own columns; pick the column your route uses (often
    | the primary key or a uuid). Paths are usually relative to your app.
    |
    |   'view_routes' => [
    |       'workers'  => '/workers/{uuid}',
    |       'epasses'  => '/epasses/{id}',
    |   ],
    |
    | This is plain config you own — your app, not the AI, decides where a
    | record opens. Tables without an entry simply get no view_url (no link).
    |
    */

    'view_routes' => [
        // 'workers' => '/workers/{uuid}',
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
