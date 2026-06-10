<?php

namespace HelfenTalk\Connect\Http\Controllers;

use HelfenTalk\Connect\Support\QueryRunner;
use HelfenTalk\Connect\Support\RoleScope;
use HelfenTalk\Connect\Support\SchemaInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single endpoint HeflenTalk calls. The request is already signature-verified
 * by the middleware. This re-derives the data scope from the plugin's own role
 * rules (never trusting the scope HeflenTalk sent) and returns role-scoped rows.
 */
class ConnectController
{
    public function handle(Request $request): JsonResponse
    {
        $userContext = (array) $request->input('user_context', []);
        $role = $userContext['role'] ?? null;

        $scope = RoleScope::resolve((array) config('helfentalk.role_rules', []), $role);

        $runner = new QueryRunner(new SchemaInspector(config('helfentalk.connection')));
        $rows = $runner->run($userContext, $scope);

        return response()->json([
            'data' => $rows,
            'scope' => $scope,
            'user_id' => $userContext['user_id'] ?? null,
        ]);
    }
}
