<?php

namespace HelfenTalk\Connect\Http\Controllers;

use HelfenTalk\Connect\Support\UserTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues a user-context JWT for the CURRENTLY AUTHENTICATED user.
 *
 * Unlike the connect/manifest/action endpoints (which are server-to-server and
 * HMAC-verified), this route is protected by the CLIENT'S OWN auth guard
 * (config('helfentalk.token.middleware')). Your dashboard front-end calls it
 * while the user is logged in and gets back a short-lived token to hand to
 * HeflenTalk's chat API — so you never have to sign the JWT yourself.
 */
class TokenController
{
    public function handle(Request $request, UserTokenIssuer $issuer): JsonResponse
    {
        $user = $request->user();

        if ($user === null)
        {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $issued = $issuer->issue($user);

        if ($issued === null)
        {
            return response()->json([
                'error' => 'Token issuance is not configured. Set HELFENTALK_KEY and helfentalk.auth.key.',
            ], 500);
        }

        return response()->json($issued);
    }
}
