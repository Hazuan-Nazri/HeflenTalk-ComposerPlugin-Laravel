<?php

namespace HelfenTalk\Connect\Support;

/**
 * Describes — for a given user role — exactly what the chatbot may do: which
 * tables it can touch, the capabilities (view/create/edit/delete) on each, the
 * readable and writable columns, whether writes are wired (a model is mapped),
 * and the scoping column. HeflenTalk reads this to build precisely the tools
 * that role is allowed; the plugin still re-enforces everything on /action.
 */
class Manifest
{
    public function __construct(protected SchemaInspector $schema)
    {
    }

    /**
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    public function build(array $userContext, string $scope): array
    {
        $role = $userContext['role'] ?? null;
        $matrix = (array) config('helfentalk.capabilities', []);
        $models = (array) config('helfentalk.models', []);
        $guarded = (array) config('helfentalk.guarded_columns', []);

        $tables = [];

        foreach ($this->schema->allowedTables() as $table)
        {
            if (! $this->schema->tableExists($table))
            {
                continue;
            }

            $capabilities = Capabilities::resolve($matrix, $role, $table);

            if ($capabilities === [])
            {
                continue;
            }

            $columns = $this->schema->columns($table);
            $writable = array_values(array_filter(
                (array) (config('helfentalk.writable_columns')[$table] ?? []),
                fn ($c) => in_array($c, $columns, true) && ! in_array($c, $guarded, true),
            ));
            $hasModel = isset($models[$table]) && is_string($models[$table]) && class_exists($models[$table]);

            // Without a mapped model, only reads are possible — advertise that.
            if (! $hasModel)
            {
                $capabilities = array_values(array_intersect($capabilities, ['view']));
            }

            $tables[] = [
                'table' => $table,
                'capabilities' => $capabilities,
                'columns' => $columns,
                'writable_columns' => $hasModel ? $writable : [],
                'writes_enabled' => $hasModel,
            ];
        }

        return [
            'scope' => $scope,
            'role' => $role,
            'max_write_rows' => (int) config('helfentalk.max_write_rows', 1),
            'tables' => $tables,
            'actions' => $this->actions($role),
        ];
    }

    /**
     * The actions-menu entries this role may use. Only safe metadata is exposed
     * (name, label, the input field names + descriptions, and whether a confirm
     * step applies) — never the controller class. Controller actions require a
     * configured acting-user model; without it the menu is inert.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function actions(?string $role): array
    {
        $model = config('helfentalk.auth.model');

        if (! is_string($model) || ! class_exists($model))
        {
            return [];
        }

        $actions = [];

        foreach ((array) config('helfentalk.actions', []) as $name => $definition)
        {
            if (! is_array($definition) || empty($definition['controller']))
            {
                continue;
            }

            $roles = $definition['roles'] ?? null;

            if (is_array($roles) && $roles !== [] && ! in_array($role, $roles, true))
            {
                continue;
            }

            $inputs = [];

            foreach ((array) ($definition['inputs'] ?? []) as $field => $description)
            {
                $inputs[(string) $field] = (string) $description;
            }

            $isRead = (bool) ($definition['read'] ?? false);

            $actions[] = [
                'name' => (string) $name,
                'label' => (string) ($definition['label'] ?? $name),
                'inputs' => $inputs,
                // Reads run immediately (no preview) and return a list to display.
                'read' => $isRead,
                'confirm' => ! $isRead && (bool) ($definition['confirm'] ?? true),
            ];
        }

        return $actions;
    }
}
