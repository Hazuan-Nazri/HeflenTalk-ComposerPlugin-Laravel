<?php

namespace HelfenTalk\Connect\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes a single chatbot data action (query / count / create / update /
 * delete) for a signature-verified user context and resolved scope.
 *
 * Safety model (the plugin is the authoritative enforcer):
 *  - capability is re-checked against the plugin's own per-role × per-table matrix;
 *  - every row is constrained by the role scope (ScopeFilter) — a table that
 *    cannot be scoped is refused unless the scope is 'all';
 *  - WRITES go through the client's Eloquent model so SoftDeletes, observers,
 *    events, validation and approval workflows all run — the chatbot can never
 *    bypass them, and raw SQL is never used to mutate;
 *  - only writable, non-guarded columns may be set;
 *  - a single update/delete may not exceed max_write_rows;
 *  - nothing is committed unless confirm === true (otherwise a preview is
 *    returned), and everything is audited.
 */
class ActionRunner
{
    public function __construct(protected SchemaInspector $schema)
    {
    }

    /**
     * @param  array<string, mixed>  $action       {table, operation, filters?, values?, confirm?, limit?}
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    public function execute(array $action, array $userContext, string $scope): array
    {
        $table = (string) ($action['table'] ?? '');
        $operation = (string) ($action['operation'] ?? '');
        $filters = (array) ($action['filters'] ?? []);
        $values = (array) ($action['values'] ?? []);
        $confirm = (bool) ($action['confirm'] ?? false);

        if (! in_array($operation, ['query', 'count', 'create', 'update', 'delete'], true))
        {
            return $this->error("Unknown operation '{$operation}'.");
        }

        if ($table === '' || ! $this->schema->isAllowed($table) || ! $this->schema->tableExists($table))
        {
            return $this->error("Table '{$table}' is not accessible.");
        }

        $role = $userContext['role'] ?? null;
        $matrix = (array) config('helfentalk.capabilities', []);
        $required = Capabilities::capabilityForOperation($operation);

        if (! Capabilities::allows($matrix, $role, $table, $required))
        {
            return $this->error("Role '{$role}' is not permitted to {$required} '{$table}'.");
        }

        return match ($operation)
        {
            'query' => $this->query($table, $filters, $userContext, $scope, (int) ($action['limit'] ?? 0)),
            'count' => $this->count($table, $filters, $userContext, $scope),
            'create' => $this->create($table, $values, $userContext, $scope, $confirm, $role),
            'update' => $this->update($table, $filters, $values, $userContext, $scope, $confirm, $role),
            'delete' => $this->delete($table, $filters, $userContext, $scope, $confirm, $role),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    protected function query(string $table, array $filters, array $userContext, string $scope, int $limit): array
    {
        $columns = $this->schema->columns($table);
        $query = DB::connection(config('helfentalk.connection'))->table($table);

        if (! ScopeFilter::apply($query, $scope, $userContext, $columns))
        {
            return $this->error('You do not have access to rows in this table.');
        }

        $this->applyFilters($query, $filters, $columns);

        $max = (int) config('helfentalk.max_rows', 25);
        $limit = $limit > 0 ? min($limit, $max) : $max;

        $rows = $query->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return ['ok' => true, 'operation' => 'query', 'table' => $table, 'rows' => $rows, 'count' => count($rows)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    protected function count(string $table, array $filters, array $userContext, string $scope): array
    {
        $columns = $this->schema->columns($table);
        $query = DB::connection(config('helfentalk.connection'))->table($table);

        if (! ScopeFilter::apply($query, $scope, $userContext, $columns))
        {
            return $this->error('You do not have access to rows in this table.');
        }

        $this->applyFilters($query, $filters, $columns);

        return ['ok' => true, 'operation' => 'count', 'table' => $table, 'count' => $query->count()];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    protected function create(string $table, array $values, array $userContext, string $scope, bool $confirm, ?string $role): array
    {
        $model = $this->modelFor($table);

        if ($model === null)
        {
            return $this->error("Creating records in '{$table}' is not enabled.");
        }

        $columns = $this->schema->columns($table);
        $clean = $this->sanitizeValues($table, $values, $columns);

        // Anchor new rows to the actor's scope (own/team) so they cannot create
        // records that fall outside what they are allowed to see.
        $clean = $this->applyOwnershipOnCreate($clean, $userContext, $scope, $columns);

        if ($clean === [])
        {
            return $this->error('No writable values were provided.');
        }

        if (! $confirm)
        {
            return ['ok' => true, 'preview' => true, 'operation' => 'create', 'table' => $table, 'values' => $clean];
        }

        try
        {
            $record = DB::connection(config('helfentalk.connection'))->transaction(
                fn () => $model::query()->create($clean)
            );
        }
        catch (Throwable $e)
        {
            return $this->error('Create failed: ' . $e->getMessage());
        }

        $this->audit($table, 'create', $userContext, $role, ['values' => $clean], 1, true);

        return ['ok' => true, 'operation' => 'create', 'table' => $table, 'created' => $record->toArray()];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    protected function update(string $table, array $filters, array $values, array $userContext, string $scope, bool $confirm, ?string $role): array
    {
        $model = $this->modelFor($table);

        if ($model === null)
        {
            return $this->error("Editing records in '{$table}' is not enabled.");
        }

        if ($filters === [])
        {
            return $this->error('A filter is required to identify which record(s) to edit.');
        }

        $columns = $this->schema->columns($table);
        $clean = $this->sanitizeValues($table, $values, $columns);

        if ($clean === [])
        {
            return $this->error('No writable values were provided.');
        }

        [$builder, $error] = $this->scopedModelQuery($model, $table, $filters, $userContext, $scope, $columns);

        if ($error !== null)
        {
            return $error;
        }

        $matched = (clone $builder)->count();
        $capError = $this->guardRowCount($matched);

        if ($capError !== null)
        {
            return $capError;
        }

        if (! $confirm)
        {
            $this->audit($table, 'update', $userContext, $role, ['filters' => $filters, 'values' => $clean], $matched, false);

            return ['ok' => true, 'preview' => true, 'operation' => 'update', 'table' => $table, 'matched' => $matched, 'changes' => $clean];
        }

        try
        {
            $affected = DB::connection(config('helfentalk.connection'))->transaction(function () use ($builder, $clean)
            {
                $n = 0;

                foreach ($builder->get() as $record)
                {
                    $record->fill($clean)->save();
                    $n++;
                }

                return $n;
            });
        }
        catch (Throwable $e)
        {
            return $this->error('Update failed: ' . $e->getMessage());
        }

        $this->audit($table, 'update', $userContext, $role, ['filters' => $filters, 'values' => $clean], $affected, true);

        return ['ok' => true, 'operation' => 'update', 'table' => $table, 'affected' => $affected];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $userContext
     * @return array<string, mixed>
     */
    protected function delete(string $table, array $filters, array $userContext, string $scope, bool $confirm, ?string $role): array
    {
        $model = $this->modelFor($table);

        if ($model === null)
        {
            return $this->error("Deleting records in '{$table}' is not enabled.");
        }

        if ($filters === [])
        {
            return $this->error('A filter is required to identify which record(s) to delete.');
        }

        $columns = $this->schema->columns($table);

        [$builder, $error] = $this->scopedModelQuery($model, $table, $filters, $userContext, $scope, $columns);

        if ($error !== null)
        {
            return $error;
        }

        $matched = (clone $builder)->count();
        $capError = $this->guardRowCount($matched);

        if ($capError !== null)
        {
            return $capError;
        }

        if (! $confirm)
        {
            $this->audit($table, 'delete', $userContext, $role, ['filters' => $filters], $matched, false);

            return ['ok' => true, 'preview' => true, 'operation' => 'delete', 'table' => $table, 'matched' => $matched];
        }

        try
        {
            $affected = DB::connection(config('helfentalk.connection'))->transaction(function () use ($builder)
            {
                $n = 0;

                foreach ($builder->get() as $record)
                {
                    // Calls the model's delete() — soft-delete if the model uses
                    // SoftDeletes, and fires the client's deleting/deleted events
                    // (so approval workflows are honored, not bypassed).
                    $record->delete();
                    $n++;
                }

                return $n;
            });
        }
        catch (Throwable $e)
        {
            return $this->error('Delete failed: ' . $e->getMessage());
        }

        $this->audit($table, 'delete', $userContext, $role, ['filters' => $filters], $affected, true);

        return ['ok' => true, 'operation' => 'delete', 'table' => $table, 'affected' => $affected];
    }

    /**
     * Build a scope- and filter-constrained Eloquent query for a write.
     *
     * @return array{0: ?\Illuminate\Database\Eloquent\Builder, 1: ?array<string, mixed>}
     */
    protected function scopedModelQuery(string $model, string $table, array $filters, array $userContext, string $scope, array $columns): array
    {
        $builder = $model::query();

        if (! ScopeFilter::apply($builder, $scope, $userContext, $columns))
        {
            return [null, $this->error('You do not have access to rows in this table.')];
        }

        $this->applyFilters($builder, $filters, $columns);

        return [$builder, null];
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $columns
     */
    protected function applyFilters($query, array $filters, array $columns): void
    {
        foreach ($filters as $column => $value)
        {
            if (! is_string($column) || ! in_array($column, $columns, true))
            {
                continue;
            }

            if (is_array($value))
            {
                $query->whereIn($column, $value);
            }
            else
            {
                $query->where($column, $value);
            }
        }
    }

    /**
     * Keep only columns that exist, are configured writable, and are not guarded.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    protected function sanitizeValues(string $table, array $values, array $columns): array
    {
        $writable = (array) (config('helfentalk.writable_columns')[$table] ?? []);
        $guarded = (array) config('helfentalk.guarded_columns', []);

        $clean = [];

        foreach ($values as $column => $value)
        {
            if (! is_string($column))
            {
                continue;
            }

            if (in_array($column, $columns, true)
                && in_array($column, $writable, true)
                && ! in_array($column, $guarded, true))
            {
                $clean[$column] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $userContext
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    protected function applyOwnershipOnCreate(array $values, array $userContext, string $scope, array $columns): array
    {
        if ($scope === 'all')
        {
            return $values;
        }

        $userColumn = (string) config('helfentalk.user_column', 'user_id');
        $teamColumn = (string) config('helfentalk.team_column', 'team_id');
        $userId = $userContext['user_id'] ?? null;
        $teamId = $userContext['team_id'] ?? ($userContext['claims']['team_id'] ?? null);

        if ($scope === 'team' && $teamId !== null && in_array($teamColumn, $columns, true))
        {
            $values[$teamColumn] = $teamId;
        }

        if ($userId !== null && in_array($userColumn, $columns, true))
        {
            $values[$userColumn] = $userId;
        }

        return $values;
    }

    protected function guardRowCount(int $matched): ?array
    {
        if ($matched === 0)
        {
            return $this->error('No matching records were found.');
        }

        $cap = (int) config('helfentalk.max_write_rows', 1);

        if ($matched > $cap)
        {
            return $this->error("This would affect {$matched} records, more than the allowed limit of {$cap}. Narrow the filter.");
        }

        return null;
    }

    /**
     * @return class-string|null
     */
    protected function modelFor(string $table): ?string
    {
        $model = config('helfentalk.models')[$table] ?? null;

        return is_string($model) && class_exists($model) ? $model : null;
    }

    /**
     * @param  array<string, mixed>  $userContext
     * @param  array<string, mixed>  $detail
     */
    protected function audit(string $table, string $operation, array $userContext, ?string $role, array $detail, int $affected, bool $committed): void
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
                'table_name' => $table,
                'operation' => $operation,
                'detail' => json_encode($detail),
                'affected_rows' => $affected,
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
