# Changelog

All notable changes to `helfentalk/laravel-plugin`. Versions are git tags on the
default branch; install a specific one with
`composer require helfentalk/laravel-plugin:^1.5`.

## v1.5.0

Checkpoint of the **controller-backed action system** — the chatbot can now read,
count and act on your data entirely through your own controllers, so your scopes,
soft-deletes, policies and API Resources always apply and nothing touches the
database directly.

### Added

- **Read actions** (`'read' => true`) — point an action at your `index`/list method;
  it runs immediately (no confirm), and the result is surfaced to the user as an
  interactive, clickable table. Each row is tagged with a `view_url` from the action's
  `view_route` template (e.g. `/workers/{id}`), and the controller's paginator
  `meta.total` is surfaced as the authoritative count.
- **Count actions** (`'count' => true`) — run the same list controller but return
  **only** the total (no rows), so "how many …?" is answered with a plain number
  instead of a table.
- **Fixed params** (`'params' => [...]`) — request params the action always forwards to
  your controller (the model can't set or override them). Use it to return enough rows
  to fill a table (`['per_page' => 50]`) or to force a sort. Merged after the
  input smuggle-guard, so they're trusted defaults.
- **`view_routes`** config — attach the same per-row `view_url` to rows returned by the
  generic `query` operation, mapping `table => '/path/{column}'`.
- README: full reference for read/count actions, `params`, and `view_routes`.

### Notes

- These let you run the **most locked-down setup**: leave the generic-CRUD
  `capabilities` empty (`[]`) and route reads, counts and writes all through your own
  controllers. The generic table-CRUD path remains available as an alternative.

## Earlier

- **Actions menu (controller-backed writes).** Map plain-English action labels to your
  controller methods; the bot runs them **as the acting user** with confirm-first
  preview, so your validation, policies/gates and approval flows fire. Every run and
  preview is audited.
- **Generic table CRUD.** Per-role × per-table capability matrix (`view`/`create`/
  `edit`/`delete`), `models` map (writes go through Eloquent so soft-deletes/observers
  run), `writable_columns`/`guarded_columns`, and `max_write_rows`. Powered by the
  signed `manifest` and `action` endpoints.
- **User-context token** — `GET /helfentalk/token` mints the short-lived HS256 JWT that
  identifies who is chatting, so you write no signing code.
- **Read context** — the original signed `POST /helfentalk/connect` endpoint that
  returns role-scoped rows for prompt injection.
- HMAC-SHA256 request signing, strict `allowed_tables` whitelist, and role-based row
  scoping (`own`/`team`/`all`) — all recomputed from the plugin's own config, never
  trusting anything HeflenTalk sends.
