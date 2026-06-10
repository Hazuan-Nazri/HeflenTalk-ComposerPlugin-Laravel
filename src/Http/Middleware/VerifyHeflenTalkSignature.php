<?php

namespace HelfenTalk\Connect\Http\Middleware;

use Closure;
use HelfenTalk\Connect\Support\Signature;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC signature on an incoming HeflenTalk request before any
 * database access. Rejects missing, stale, or invalid signatures.
 */
class VerifyHeflenTalkSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('helfentalk.api_key');

        if (empty($secret))
        {
            abort(500, 'HeflenTalk Connect is not configured (set HELFENTALK_KEY).');
        }

        $timestamp = $request->header('X-HeflenTalk-Timestamp');
        $signature = $request->header('X-HeflenTalk-Signature');

        if (empty($timestamp) || empty($signature))
        {
            abort(401, 'Missing HeflenTalk signature.');
        }

        $tolerance = (int) config('helfentalk.signature_tolerance', 300);

        if (abs(time() - (int) $timestamp) > $tolerance)
        {
            abort(401, 'HeflenTalk request has expired.');
        }

        if (! Signature::verify($secret, (string) $timestamp, $request->getContent(), $signature))
        {
            abort(401, 'Invalid HeflenTalk signature.');
        }

        return $next($request);
    }
}
