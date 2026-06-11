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
`HELFENTALK_KEY`. The same secret is what you use to sign the **user-context JWT**
on your side, so the user identity HeflenTalk forwards is trustworthy.

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

## Data actions (read + write)

When you map models and grant capabilities, HeflenTalk can let the chatbot
**find, count, create, edit, change status and delete** records — all within the
limits you set. Two more signed endpoints power this (same HMAC contract):

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
