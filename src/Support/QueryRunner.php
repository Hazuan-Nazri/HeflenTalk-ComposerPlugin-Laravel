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
        $userId = $userContext['user_id'] ?? null;
        $teamId = $userContext['team_id'] ?? ($userContext['claims']['team_id'] ?? null);

        $userColumn = (string) config('helfentalk.user_column', 'user_id');
        $teamColumn = (string) config('helfentalk.team_column', 'team_id');
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

            if ($scope === 'own' && $userId !== null && in_array($userColumn, $columns, true))
            {
                $query->where($userColumn, $userId);
            }
            elseif ($scope === 'team' && $teamId !== null && in_array($teamColumn, $columns, true))
            {
                $query->where($teamColumn, $teamId);
            }
            elseif ($scope === 'team' && $userId !== null && in_array($userColumn, $columns, true))
            {
                // No team column to scope by — fall back to the user's own rows.
                $query->where($userColumn, $userId);
            }
            elseif ($scope !== 'all' && $userId !== null && in_array($userColumn, $columns, true))
            {
                // Unknown scope defaults to own rows — never leak everything.
                $query->where($userColumn, $userId);
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
