<?php

namespace HelfenTalk\Connect\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reads role-scoped rows from the whitelisted tables. The allowed_tables list
 * is enforced strictly — the plugin never queries outside it — and rows are
 * always constrained by the resolved scope.
 */
class QueryRunner
{
    public function __construct(protected SchemaInspector $schema)
    {
    }

    /**
     * @param  array<string, mixed>  $userContext
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function run(array $userContext, string $scope): array
    {
        $maxRows = (int) config('helfentalk.max_rows', 25);
        $connection = config('helfentalk.connection');

        $result = [];

        foreach ($this->schema->allowedTables() as $table)
        {
            if (! $this->schema->tableExists($table))
            {
                continue;
            }

            $columns = $this->schema->columns($table);
            $query = DB::connection($connection)->table($table);

            // If the table cannot be scoped and the scope is not 'all', skip it
            // entirely rather than leak unscoped rows.
            if (! ScopeFilter::apply($query, $scope, $userContext, $columns))
            {
                continue;
            }

            $rows = $query->limit($maxRows)->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            if ($rows !== [])
            {
                $result[$table] = $rows;
            }
        }

        return $result;
    }
}
