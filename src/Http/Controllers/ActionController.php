<?php

namespace HelfenTalk\Connect\Http\Controllers;

use HelfenTalk\Connect\Support\ActionRunner;
use HelfenTalk\Connect\Support\RoleScope;
use HelfenTalk\Connect\Support\SchemaInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Executes a single chatbot data action. Already signature-verified by the
 * middleware. Re-derives the scope from the plugin's own role rules and hands
 * off to ActionRunner, which re-enforces capabilities, scope, column rules,
 * row caps and the confirm/preview gate.
 */
class ActionController
{
    public function handle(Request $request): JsonResponse
    {
        $userContext = (array) $request->input('user_context', []);
        $action = (array) $request->input('action', []);
        $role = $userContext['role'] ?? null;

        $scope = RoleScope::resolve((array) config('helfentalk.role_rules', []), $role);

        $runner = new ActionRunner(new SchemaInspector(config('helfentalk.connection')));
        $result = $runner->execute($action, $userContext, $scope);

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
