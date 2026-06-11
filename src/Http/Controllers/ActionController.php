<?php

namespace HelfenTalk\Connect\Http\Controllers;

use HelfenTalk\Connect\Support\ActionDispatcher;
use HelfenTalk\Connect\Support\ActionRunner;
use HelfenTalk\Connect\Support\RoleScope;
use HelfenTalk\Connect\Support\SchemaInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Executes a single chatbot action. Already signature-verified by the
 * middleware. Two kinds of action share this endpoint:
 *
 *  - a named action from the actions menu (action.name matches a declared
 *    config('helfentalk.actions') key) → ActionDispatcher runs the client's own
 *    controller as the acting user (their validation/policies/approval all run);
 *  - a generic table action (operation = query/count/create/update/delete) →
 *    ActionRunner enforces capabilities, scope, column rules, row caps and the
 *    confirm/preview gate against a mapped Eloquent model.
 */
class ActionController
{
    public function handle(Request $request): JsonResponse
    {
        $userContext = (array) $request->input('user_context', []);
        $action = (array) $request->input('action', []);
        $role = $userContext['role'] ?? null;

        $name = (string) ($action['name'] ?? '');

        if ($name !== '' && ActionDispatcher::isDeclared($name))
        {
            $dispatcher = new ActionDispatcher(new SchemaInspector(config('helfentalk.connection')));
            $result = $dispatcher->dispatch($action, $userContext);
        }
        else
        {
            $scope = RoleScope::resolve((array) config('helfentalk.role_rules', []), $role);

            $runner = new ActionRunner(new SchemaInspector(config('helfentalk.connection')));
            $result = $runner->execute($action, $userContext, $scope);
        }

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
