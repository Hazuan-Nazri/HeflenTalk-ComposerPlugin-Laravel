<?php

namespace HelfenTalk\Connect\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs a chatbot action through one of the client's OWN controller methods,
 * declared in config('helfentalk.actions'). This is the controller-backed path:
 * the client lists which actions the bot may perform (label -> controller +
 * inputs), and the dispatcher invokes that real method as the acting user — so
 * the client's validation, policies/gates, if/else logic and approval flows all
 * run exactly as for a normal request. The bot can only do what is on the menu,
 * and can never bypass the controller's rules.
 *
 * Safety model:
 *  - the action must be declared in config (the menu is the boundary);
 *  - an optional per-action role allow-list gates visibility;
 *  - the acting user is resolved from the verified user context and logged in,
 *    so auth()->user(), Gate and Policy checks behave normally;
 *  - confirm-first: with confirm=false the controller is NOT called — a preview
 *    of the exact inputs is returned; only confirm=true actually runs it;
 *  - validation and authorization failures from the controller are surfaced to
 *    the user verbatim (never silently ignored);
 *  - every run (and preview) is audited.
 */
class ActionDispatcher
{
    public function __construct(protected SchemaInspector $schema)
    {
    }

    /**
     * @param  array<string, mixed>  $action       {name, values?, confirm?}
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    public function dispatch(array $action, array $userContext): array
    {
        $name = (string) ($action['name'] ?? '');
        $values = (array) ($action['values'] ?? []);
        $confirm = (bool) ($action['confirm'] ?? false);

        $definition = $this->definitionFor($name);

        if ($definition === null)
        {
            return $this->error("Action '{$name}' is not available.");
        }

        $role = $userContext['role'] ?? null;

        if (! $this->roleAllowed($definition, $role))
        {
            return $this->error("Role '{$role}' is not permitted to perform '{$name}'.");
        }

        [$controllerClass, $method] = $this->callable($definition);

        if ($controllerClass === null)
        {
            return $this->error("Action '{$name}' is misconfigured (no controller).");
        }

        // Keep only inputs the action declares — the model can't smuggle extras.
        $inputs = $this->declaredInputs($definition, $values);

        // Fixed params the client always wants forwarded to its controller —
        // e.g. a larger `per_page` so a read action returns enough rows to fill a
        // table, or a forced sort. Declared in config (trusted), not model-
        // supplied, so they're merged in AFTER the smuggle-guard. A value the
        // model explicitly set still wins, so these act as client-set defaults.
        $inputs = array_merge($this->fixedParams($definition), $inputs);

        // A count action runs the SAME list controller but returns only the
        // total — for "how many …?" questions. It surfaces no rows, so the app
        // shows a plain number, never a table. A count is a kind of read: it runs
        // immediately and changes nothing.
        $isCount = (bool) ($definition['count'] ?? false);

        // A read action lists/searches records through the client's own
        // controller (e.g. WorkerController@index) — it runs immediately (no
        // confirm) and its result is returned as a structured, clickable table.
        $isRead = $isCount || (bool) ($definition['read'] ?? false);

        // confirm-first: never run the controller on a preview. Show the user the
        // exact action and inputs; only a confirmed call actually executes. Reads
        // never preview (they change nothing).
        $needsConfirm = ! $isRead && (bool) ($definition['confirm'] ?? true);

        if ($needsConfirm && ! $confirm)
        {
            $this->audit($name, $userContext, $role, $inputs, false);

            return [
                'ok' => true,
                'preview' => true,
                'action' => $name,
                'label' => $definition['label'] ?? $name,
                'inputs' => $inputs,
            ];
        }

        $user = $this->resolveUser($userContext);

        if ($user === null)
        {
            return $this->error('The action service is not configured to identify the user.');
        }

        try
        {
            $result = $this->invoke($controllerClass, $method, $definition, $inputs, $user);
        }
        catch (ValidationException $e)
        {
            return ['ok' => false, 'error' => 'The action was rejected by validation.', 'errors' => $e->errors()];
        }
        catch (AuthorizationException $e)
        {
            return $this->error('You are not authorized to perform this action.');
        }
        catch (HttpExceptionInterface $e)
        {
            if (in_array($e->getStatusCode(), [401, 403], true))
            {
                return $this->error('You are not authorized to perform this action.');
            }

            return $this->error('The action could not be completed: ' . $e->getMessage());
        }
        catch (Throwable $e)
        {
            return $this->error('The action could not be completed: ' . $e->getMessage());
        }

        $this->audit($name, $userContext, $role, $inputs, true);

        // A count action returns ONLY the total — no rows — so the app answers a
        // "how many?" question with a plain number and never renders a table. The
        // total is the controller's authoritative count (paginator meta), already
        // scoped with soft-deletes excluded; it falls back to the returned row
        // count when the response isn't paginated.
        if ($isCount)
        {
            return [
                'ok' => true,
                'action' => $name,
                'count_only' => true,
                'entity' => (string) ($definition['entity'] ?? $name),
                'total' => $this->extractTotal($result) ?? count($this->extractRows($result)),
            ];
        }

        // A read action returns its controller's list as structured rows (each
        // tagged with a view_url) so the app renders an interactive, clickable
        // table — the same shape a generic query would produce, but routed fully
        // through the controller (auth, scopes, soft-deletes and the API resource
        // all applied).
        if ($isRead)
        {
            $rows = $this->withViewUrls(
                (string) ($definition['view_route'] ?? ''),
                $this->extractRows($result),
            );

            return [
                'ok' => true,
                'action' => $name,
                'read' => true,
                'entity' => (string) ($definition['entity'] ?? $name),
                'rows' => $rows,
                'count' => count($rows),
                // The controller's own total (paginator meta) — the AUTHORITATIVE
                // count, already scoped and with soft-deletes excluded. Null when
                // the response isn't paginated.
                'total' => $this->extractTotal($result),
            ];
        }

        return ['ok' => true, 'action' => $name, 'result' => $result];
    }

    /**
     * Pull the list of records out of whatever the controller returned —
     * unwrapping a Laravel API-resource collection envelope ({ data: [...] }) or
     * a bare list. Non-list responses yield no rows (handled as a plain result).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractRows(mixed $result): array
    {
        $data = is_array($result) ? ($result['data'] ?? $result) : $result;

        if (is_array($data) && isset($data['data']) && is_array($data['data']))
        {
            $data = $data['data'];
        }

        if (is_array($data) && array_is_list($data))
        {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    /**
     * Best-effort total from a paginator envelope (meta.total) — the controller's
     * authoritative count. Null when the response carries no such meta.
     */
    protected function extractTotal(mixed $result): ?int
    {
        $data = is_array($result) ? ($result['data'] ?? $result) : $result;

        if (is_array($data) && isset($data['meta']['total']) && is_numeric($data['meta']['total']))
        {
            return (int) $data['meta']['total'];
        }

        return null;
    }

    /**
     * Attach a `view_url` to each row from the action's route template
     * (e.g. '/workers/{id}'). Placeholders are filled from the row's own fields;
     * a row whose template can't be fully resolved is left without a view_url.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function withViewUrls(string $template, array $rows): array
    {
        if ($template === '')
        {
            return $rows;
        }

        return array_map(function (array $row) use ($template)
        {
            $url = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($row)
            {
                return array_key_exists($m[1], $row) ? rawurlencode((string) $row[$m[1]]) : $m[0];
            }, $template);

            if (! preg_match('/\{\w+\}/', $url))
            {
                $row['view_url'] = $url;
            }

            return $row;
        }, $rows);
    }

    /**
     * Whether a named action is declared in the actions menu.
     */
    public static function isDeclared(string $name): bool
    {
        return $name !== '' && array_key_exists($name, (array) config('helfentalk.actions', []));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function definitionFor(string $name): ?array
    {
        $definition = config('helfentalk.actions')[$name] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function roleAllowed(array $definition, ?string $role): bool
    {
        $roles = $definition['roles'] ?? null;

        if (! is_array($roles) || $roles === [])
        {
            return true;
        }

        return in_array($role, $roles, true);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{0: ?string, 1: string}
     */
    protected function callable(array $definition): array
    {
        $controller = $definition['controller'] ?? null;

        if (is_array($controller) && isset($controller[0]) && is_string($controller[0]))
        {
            return [$controller[0], (string) ($controller[1] ?? '__invoke')];
        }

        if (is_string($controller) && str_contains($controller, '@'))
        {
            [$class, $method] = explode('@', $controller, 2);

            return [$class, $method];
        }

        if (is_string($controller) && $controller !== '')
        {
            return [$controller, '__invoke'];
        }

        return [null, ''];
    }

    /**
     * Keep only the inputs the action declares (by their real field names).
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function declaredInputs(array $definition, array $values): array
    {
        $declared = array_keys((array) ($definition['inputs'] ?? []));

        if ($declared === [])
        {
            return $values;
        }

        return array_intersect_key($values, array_flip($declared));
    }

    /**
     * Fixed request params the action always forwards to the controller (e.g.
     * `['per_page' => 50]`). Client-declared, so trusted; merged in as defaults.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    protected function fixedParams(array $definition): array
    {
        $params = $definition['params'] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Invoke the controller method as the acting user, with the declared inputs
     * available both as request input (for FormRequest validation) and as named
     * arguments (for scalar/model-bound parameters).
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $inputs
     * @return mixed
     */
    protected function invoke(string $controllerClass, string $method, array $definition, array $inputs, Model $user)
    {
        $app = app();
        $guard = config('helfentalk.auth.guard');

        Auth::guard($guard ?: null)->setUser($user);

        // Bind a request carrying the inputs so resolved FormRequests validate
        // against them and the controller can read request input as usual.
        $request = Request::create('/', 'POST', $inputs);
        $request->setUserResolver(fn () => $user);
        $previous = $app->bound('request') ? $app->make('request') : null;
        $app->instance('request', $request);

        // Resolve any declared route-model bindings to instances passed by name.
        $parameters = $inputs;

        foreach ((array) ($definition['bindings'] ?? []) as $param => $modelClass)
        {
            if (is_string($modelClass) && class_exists($modelClass))
            {
                $id = $inputs[$param] ?? $inputs['id'] ?? null;
                $parameters[$param] = $id !== null ? $modelClass::query()->findOrFail($id) : null;
            }
        }

        try
        {
            $controller = $app->make($controllerClass);

            $returned = $app->call([$controller, $method], $parameters);
        }
        finally
        {
            if ($previous !== null)
            {
                $app->instance('request', $previous);
            }
        }

        return $this->normalize($returned);
    }

    /**
     * Turn whatever the controller returned into a chatbot-friendly payload.
     *
     * @return mixed
     */
    protected function normalize(mixed $returned)
    {
        if ($returned instanceof JsonResponse)
        {
            return ['status' => $returned->getStatusCode(), 'data' => $returned->getData(true)];
        }

        if ($returned instanceof RedirectResponse)
        {
            return ['status' => $returned->getStatusCode(), 'message' => 'Completed.'];
        }

        if ($returned instanceof Response)
        {
            $body = $returned->getContent();
            $decoded = json_decode($body ?: 'null', true);

            return ['status' => $returned->getStatusCode(), 'data' => $decoded ?? $body];
        }

        if ($returned instanceof Model || $returned instanceof Arrayable)
        {
            return $returned->toArray();
        }

        if (is_array($returned) || is_scalar($returned) || $returned === null)
        {
            return $returned;
        }

        return ['message' => 'Completed.'];
    }

    /**
     * @param  array<string, mixed>  $userContext
     */
    protected function resolveUser(array $userContext): ?Model
    {
        $model = config('helfentalk.auth.model');

        if (! is_string($model) || ! class_exists($model))
        {
            return null;
        }

        $key = (string) config('helfentalk.auth.key', 'id');
        $userId = $userContext['user_id'] ?? null;

        if ($userId === null)
        {
            return null;
        }

        $found = $model::query()->where($key, $userId)->first();

        return $found instanceof Model ? $found : null;
    }

    /**
     * @param  array<string, mixed>  $userContext
     * @param  array<string, mixed>  $inputs
     */
    protected function audit(string $name, array $userContext, ?string $role, array $inputs, bool $committed): void
    {
        if (! config('helfentalk.audit.enabled', true))
        {
            return;
        }

        $auditTable = (string) config('helfentalk.audit.table', 'helfentalk_audit_logs');
        $connection = config('helfentalk.connection');

        try
        {
            if (! $this->schema->tableExists($auditTable))
            {
                return;
            }

            DB::connection($connection)->table($auditTable)->insert([
                'user_id' => $userContext['user_id'] ?? null,
                'role' => $role,
                'table_name' => $name,
                'operation' => 'action',
                'detail' => json_encode(['inputs' => $inputs]),
                'affected_rows' => 0,
                'committed' => $committed,
                'created_at' => now(),
            ]);
        }
        catch (Throwable $e)
        {
            // Auditing must never break the action; swallow.
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
