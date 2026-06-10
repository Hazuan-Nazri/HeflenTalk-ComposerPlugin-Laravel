# HeflenTalk Connect — Laravel plugin

`helfentalk/laravel-plugin` lets a HeflenTalk tenant securely expose their own
data to their chatbot **without the data ever leaving their server**. HeflenTalk
calls a single signed endpoint inside your app; the plugin verifies the request,
applies role rules, queries only whitelisted tables, and returns role-scoped rows.

## Install

```bash
composer require helfentalk/laravel-plugin
php artisan vendor:publish --tag=helfentalk-config
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
    'allowed_tables' => ['employees', 'leaves', 'payslips'],
    'role_rules'     => [
        'employee' => ['own_data_only' => true],
        'manager'  => ['scope' => 'team'],
        'admin'    => ['scope' => 'all'],
    ],
    'user_column'    => 'user_id',
    'team_column'    => 'team_id',
];
```

- **`allowed_tables`** — the only tables the plugin will ever read. Schema/columns
  are auto-discovered; you never register endpoints per table.
- **`role_rules`** — maps the user's role to a data scope (`own` / `team` / `all`).

Finally, give HeflenTalk your endpoint URL (Bot settings → Connect):

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

## Security

- Every request is **HMAC-verified** before any DB access; missing, stale
  (> tolerance), or invalid signatures are rejected with 401.
- The **`allowed_tables` whitelist is enforced strictly** — the plugin never
  touches a table outside it.
- Rows are always **scoped by role**: `own` filters by `user_column = user_id`,
  `team` by `team_column = team_id`; an unknown role falls back to `own` (never
  leaks everything).
- The plugin **recomputes the scope from its own `role_rules`** and never trusts
  the `scope` field HeflenTalk sends.

## Test

```bash
composer install
composer test
```

> Sibling plugins for other stacks: `helfentalk-express` (Node) and
> `helfentalk-django` (Python) follow the same signed-request contract.
