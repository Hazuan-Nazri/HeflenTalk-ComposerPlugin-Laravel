<?php

namespace HelfenTalk\Connect\Support;

/**
 * Applies the resolved role scope (own / team / all) to a query as a WHERE
 * constraint, so a user only ever sees or mutates rows within their scope.
 *
 * Shared by reads (QueryRunner) and writes (ActionRunner). For writes this is
 * the row-level guard: if a restriction is required but cannot be applied
 * (the table has no scoping column), apply() returns false and the caller MUST
 * refuse the operation rather than touch unscoped rows.
 */
class ScopeFilter
{
    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $userContext
     * @param  array<int, string>  $columns
     * @return bool  true if safely scoped (or scope is 'all'); false if a required restriction could not be applied
     */
    public static function apply($query, string $scope, array $userContext, array $columns): bool
    {
        if ($scope === 'all')
        {
            return true;
        }

        $userId = $userContext['user_id'] ?? null;
        $teamId = $userContext['team_id'] ?? ($userContext['claims']['team_id'] ?? null);

        $userColumn = (string) config('helfentalk.user_column', 'user_id');
        $teamColumn = (string) config('helfentalk.team_column', 'team_id');

        if ($scope === 'team' && $teamId !== null && in_array($teamColumn, $columns, true))
        {
            $query->where($teamColumn, $teamId);

            return true;
        }

        // 'own', or 'team' with no team column — fall back to the user's own rows.
        if ($userId !== null && in_array($userColumn, $columns, true))
        {
            $query->where($userColumn, $userId);

            return true;
        }

        return false;
    }
}
