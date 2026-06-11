<?php

namespace HelfenTalk\Connect\Http\Controllers;

use HelfenTalk\Connect\Support\Manifest;
use HelfenTalk\Connect\Support\RoleScope;
use HelfenTalk\Connect\Support\SchemaInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the capability/schema manifest for the requesting user's role. The
 * request is already signature-verified by the middleware. Scope and
 * capabilities are recomputed from the plugin's OWN config — never trusted from
 * HeflenTalk.
 */
class ManifestController
{
    public function handle(Request $request): JsonResponse
    {
        $userContext = (array) $request->input('user_context', []);
        $role = $userContext['role'] ?? null;

        $scope = RoleScope::resolve((array) config('helfentalk.role_rules', []), $role);

        $manifest = new Manifest(new SchemaInspector(config('helfentalk.connection')));

        return response()->json($manifest->build($userContext, $scope));
    }
}
