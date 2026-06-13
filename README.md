# HeflenTalk Connect — Laravel plugin

`helfentalk/laravel-plugin` lets a HeflenTalk tenant securely expose their own
data to their chatbot **without the data ever leaving their server**. HeflenTalk
calls signed endpoints inside your app; the plugin verifies each request, applies
your role rules, and **reads or modifies only what you allow** — per role, per
table, per operation (view / create / edit / delete).

Writes go **through your own Eloquent models**, so your soft-deletes, observers,
validation and approval workflows all run — the chatbot can never bypass them.

## Install

```bash
composer require helfentalk/laravel-plugin
php artisan vendor:publish --tag=helfentalk-config

# Only needed if you enable write actions (for the audit log):
php artisan vendor:publish --tag=helfentalk-migrations
php artisan migrate
```

Then set your Connect secret (from the HeflenTalk dashboard → API Keys / Connect)
in `.env`:

```dotenv
HELFENTALK_KEY=htc_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

## Configure

`config/helfentalk.php`:

```php
return [
    'api_key'        => env('HELFENTALK_KEY'),

    // Tables the plugin may touch. Use 'all' for every table, or a list.
    'allowed_tables' => ['workers', 'epasses', 'documents'],

    // Scope: which ROWS a role may see/touch (own / team / all).
    'role_rules'     => [
        'employee' => ['own_data_only' => true],
        'manager'  => ['scope' => 'team'],
        'admin'    => ['scope' => 'all'],
    ],
    'user_column'    => 'user_id',
    'team_column'    => 'team_id',

    // --- Data actions (optional; omit for read-only) -------------------

    // Map a table to its model — REQUIRED for writes so your business logic
    // (SoftDeletes, observers, approval) runs. No model => read-only.
    'models' => [
        'workers' => \App\Models\Worker::class,
    ],

    // What each role may DO per table: view / create / edit / delete.
    // 'all' applies to every allowed table; a table key overrides it.
    'capabilities' => [
        'admin'    => ['all' => ['view', 'create', 'edit', 'delete']],
        'manager'  => ['workers' => ['view', 'edit'], 'all' => ['view']],
        'employee' => ['all' => ['view']],
    ],

    // Columns the chatbot may set on create/edit (per table). Nothing else
    // is ever written.
    'writable_columns' => [
        'workers' => ['status', 'notes'],
    ],

    'max_write_rows' => 1,  // cap rows one update/delete may affect
];
```

- **`allowed_tables`** — the only tables the plugin will ever touch (`'all'` or a
  list). Columns are auto-discovered; you never register endpoints per table.
- **`role_rules`** — row scope per role (`own` / `team` / `all`).
- **`models`** — required for writes; writes call your model so your own rules run.
- **`capabilities`** — the per-role × per-table action matrix (`view` is the base).
- **`writable_columns`** / **`guarded_columns`** — exactly which columns may change.

Finally, in HeflenTalk (Bot settings → **Connect — data actions**): set your
endpoint URL, enable Connect, and tick **Enable data actions** to allow writes:

```
https://your-app.com/helfentalk/connect
```

## How it works

```
HeflenTalk-be  ──(HMAC-signed POST)──▶  /helfentalk/connect
                                              │ verify signature (HELFENTALK_KEY)
                                              │ resolve scope from role_rules
                                              │ query allowed_tables, scoped by role
                                              ▼
                                        { "data": { ...rows... } }  ──▶ HeflenTalk
                                              injects into the AI prompt
```

The signature covers `"{timestamp}.{rawBody}"` with HMAC-SHA256 using your
`HELFENTALK_KEY`. The same secret signs the **user-context JWT** that identifies
who is chatting — and the plugin can mint that JWT for you (see below), so you
write no signing code.

## User-context token — zero signing code (recommended)

Your chat UI must tell HeflenTalk *who* is chatting, as a short-lived JWT signed
with your Connect secret. Instead of signing it yourself, the plugin exposes:

```
GET /helfentalk/token          (guarded by YOUR auth, not HMAC)
→ { "token": "<jwt>", "expires_in": 900 }
```

Your front-end calls it while the user is logged in, then sends the token to
HeflenTalk's chat API as `user_context.token`. The JWT carries `user_id` (from
`auth.key`), `name`, `role` and optionally `department` — HS256-signed with
`HELFENTALK_KEY`.

```php
// config/helfentalk.php
'token' => [
    'enabled'    => true,
    // YOUR auth guard for this route (NOT the HMAC used by connect/action):
    'middleware' => ['auth:sanctum'],   // or ['auth'] for session dashboards
    'ttl'        => 900,                 // seconds
    // role auto-detects Spatie getRoleNames() or a 'role' attribute;
    // set these only to override:
    'role_field' => null,
    'name_field' => null,
    'department_field' => null,
],
```

Front-end wiring (the whole integration):

```js
// 1. get a fresh user-context token from your own app
const { token } = await fetch('/helfentalk/token', { credentials: 'include' })
    .then(r => r.json());

// 2. send the chat message to HeflenTalk with that token
await fetch('https://heflentalk.com/api/widget/chat', {
    method: 'POST',
    headers: { 'X-Bot-Key': '<bot public key>', 'Content-Type': 'application/json' },
    body: JSON.stringify({ message, user_context: { token } }),
});
```

Prefer to sign it yourself? Set `'enabled' => false` and issue an HS256 JWT with
the same claims and secret.

### Request (from HeflenTalk → plugin)

```http
POST /helfentalk/connect
X-HeflenTalk-Timestamp: 1700000000
X-HeflenTalk-Signature: <hmac-sha256>
Content-Type: application/json

{ "message": "Berapa baki cuti saya?",
  "scope": "own",
  "user_context": { "user_id": 123, "name": "Ahmad", "role": "employee" } }
```

### Response

```json
{ "data": { "leaves": [{ "user_id": 123, "balance_days": 12 }] },
  "scope": "own", "user_id": 123 }
```

## Actions menu — let the chatbot run YOUR controllers (recommended)

The cleanest way to let the chatbot *do* things is to point it at your own
controller methods. You list the actions it may perform; each one runs **through
your controller**, so your validation, authorization (policies/gates), `if/else`
logic and approval flows all execute exactly as they do for a normal web request.
The chatbot can only ever do what is on the list, and can never bypass your rules.

### How it decides which controller to run

You write a small **menu** in config — a plain-English label for each action and
the controller method it maps to. The AI reads the *labels* (never your code) and
picks the one that matches what the user asked. Anything not on the menu simply
does not exist to the bot.

```php
// config/helfentalk.php

// So the plugin can run your controllers AS the person chatting (auth()->user(),
// policies and gates work normally):
'auth' => [
    'model' => \App\Models\User::class,
    'key'   => 'id',   // column matched against the user-context user_id
],

'actions' => [

    'change_worker_status' => [
        'label'      => 'Change a worker’s status',
        'controller' => [\App\Http\Controllers\WorkerController::class, 'update'],
        'inputs'     => [
            // Use your REAL field names — the ones your controller/validation expect.
            'worker' => 'The worker ID to update',
            'status' => 'New status: "active" or "inactive"',
        ],
        'confirm'  => true,            // preview + ask before the controller runs
        'roles'    => ['admin', 'manager'],   // optional allow-list
        'bindings' => ['worker' => \App\Models\Worker::class], // route-model binding
    ],

],
```

That's the whole mapping. With this, *"make Ahmad inactive"* becomes: the bot looks
up Ahmad's id, matches the **"Change a worker's status"** action, shows you a preview
(*"set worker 1 to inactive — confirm?"*), and only on your **yes** calls
`WorkerController@update` — where your validation and approval run for real.

### Three rules that make it work

1. **Point at methods that DO the work and RETURN data** — `update`, `store`,
   `destroy` — not the form-rendering `edit` / `create` / `show` (those return a
   page and write nothing).
2. **Declare inputs with your real field/column names.** The AI fills inputs from
   the conversation and sends exactly those keys. If your column is
   `employment_state`, name the input `employment_state` (not `status`) — otherwise
   your controller never sees the value and nothing changes.
3. **Put enforceable logic where the controller runs it** — in the controller, a
   Form Request, a Policy/Gate, or a model observer. If a rule lives somewhere the
   `update`/`store`/`destroy` call doesn't reach, it won't fire. (Approval that
   currently lives only in a *different* controller action should move into the
   model/observer, or just leave that action off the menu.)

### What the bot returns to the user

- **Validation fails** (e.g. status not `active`/`inactive`) → the bot relays the
  exact validation message; nothing is written.
- **Policy/authorization denies** → the bot says you're not allowed; nothing is written.
- **Not on the menu / typo'd controller** → the bot can't do it (safe failure); it
  never guesses a different action.

Every run and preview is recorded in the audit log.

### List and count through your controllers (read & count actions)

The actions menu isn't only for writes — point it at your `index`/list methods to let
the bot **find, list and count** records through your own controller, so your
company/tenant scopes, soft-deletes, policies and API Resource all apply. These run
**immediately** (no confirm — they change nothing).

```php
'actions' => [

    // LIST — shown to the user as an interactive, clickable table.
    'list_workers' => [
        'label'      => 'List or search workers (by status, etc.)',
        'controller' => [\App\Http\Controllers\WorkerController::class, 'index'],
        'read'       => true,                 // a read action: runs now, returns rows
        'entity'     => 'workers',            // table label
        'view_route' => '/workers/{id}',      // each row becomes a deep link
        'inputs'     => ['status' => 'Optional filter: active / inactive'],
        'params'     => ['per_page' => 50],   // fixed query params your index() reads
        'roles'      => ['admin', 'manager'],
    ],

    // COUNT — answers "how many …?" with just a number (no table).
    'count_workers' => [
        'label'      => 'Count workers (returns only the number)',
        'controller' => [\App\Http\Controllers\WorkerController::class, 'index'],
        'count'      => true,                  // returns only the total, no rows
        'entity'     => 'workers',
        'inputs'     => ['status' => 'Optional filter'],
        'params'     => ['per_page' => 1],
        'roles'      => ['admin', 'manager'],
    ],

],
```

- **`read` / `count`** — `read` returns the list as a clickable table; `count` returns
  only the paginator total (your authoritative, already-scoped count — no rows, so the
  app shows a plain number). Both run immediately and never write.
- **`view_route`** — a deep-link template; `{placeholders}` are filled from each row's
  own fields, so clicking a row opens its page in your app.
- **`inputs`** — OPTIONAL filters your `index()` understands; the bot may omit them to
  list everything.
- **`params`** — fixed request params ALWAYS sent to your controller (the bot can't set
  or override them) — e.g. a larger `per_page` so a list fills a table, or a forced sort.

Your `index()` should return JSON — a paginated API-resource collection
(`{ "data": [...], "meta": { "total": N } }`) or a bare list. The plugin unwraps the
envelope, attaches a `view_url` to each row, and surfaces `meta.total` as the count.

> **Reads through your controllers, too.** Because `index` actions already apply your
> scopes and soft-deletes, you can leave the generic-CRUD `capabilities` empty (`[]`)
> and route **everything** — reads, counts and writes — through your own controllers.
> This is the most locked-down setup: the plugin never touches the database directly.

## Data actions via generic table CRUD (alternative)

If you'd rather not wire controllers, you can instead map models and grant
capabilities, and HeflenTalk can let the chatbot **find, count, create, edit,
change status and delete** records through the model layer — all within the
limits you set. Two more signed endpoints power both this and the actions menu
(same HMAC contract):

- **`POST /helfentalk/manifest`** — tells HeflenTalk what the user's role may do
  (tables, capabilities, writable columns), so it only ever offers permitted
  actions. The plugin still re-enforces everything on every action.
- **`POST /helfentalk/action`** — executes one action:

  ```json
  { "user_context": { "user_id": 1, "role": "admin" },
    "action": { "table": "workers", "operation": "update",
                "filters": { "id": 42 }, "values": { "status": "inactive" },
                "confirm": false } }
  ```

**Confirm before write.** With `confirm: false` (the default) the plugin only
**previews** — it returns the matched count and the exact change and commits
nothing. The chatbot shows that to the user and only re-sends with
`confirm: true` after they agree. Nothing is written until you (the end user)
say yes.

**Your process is law.** Deletes call `$model->delete()`, so a model using
`SoftDeletes` is soft-deleted, and any `deleting`/`saving` observers or approval
workflows you have run normally. If your delete needs approval, the chatbot
cannot skip it.

**Clickable rows from generic queries.** To turn rows from the generic `query`
operation into deep links too, add a `view_routes` map — the same per-row `view_url`
that read actions produce:

```php
'view_routes' => [
    'workers' => '/workers/{id}',   // {id} is filled from each row
],
```

## Security

- Every request is **HMAC-verified** before any DB access; missing, stale
  (> tolerance), or invalid signatures are rejected with 401.
- The **`allowed_tables` whitelist is enforced strictly** — the plugin never
  touches a table outside it.
- Rows are always **scoped by role**: `own` filters by `user_column = user_id`,
  `team` by `team_column = team_id`; an unknown role falls back to `own` (never
  leaks everything).
- The plugin **recomputes the scope and capabilities from its own config** and
  never trusts anything HeflenTalk sends.
- **Writes are gated four ways:** the role must have the capability, the table
  must map to a model, only `writable_columns` (minus `guarded_columns`) may be
  set, and a single update/delete may not exceed `max_write_rows`.
- Writes run through your **Eloquent models inside a transaction** — never raw
  SQL — so soft-deletes, observers and approval flows always fire.
- Every write (and preview) is recorded in the **audit log** (`helfentalk_audit_logs`).

## Test

```bash
composer install
composer test
```

> Sibling plugins for other stacks: `helfentalk-express` (Node) and
> `helfentalk-django` (Python) follow the same signed-request contract.
