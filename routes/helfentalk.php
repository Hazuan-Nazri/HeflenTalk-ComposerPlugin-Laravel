<?php

use HelfenTalk\Connect\Http\Controllers\ActionController;
use HelfenTalk\Connect\Http\Controllers\ConnectController;
use HelfenTalk\Connect\Http\Controllers\ManifestController;
use HelfenTalk\Connect\Http\Controllers\TokenController;
use HelfenTalk\Connect\Http\Middleware\VerifyHeflenTalkSignature;
use Illuminate\Support\Facades\Route;

$prefix = config('helfentalk.route_prefix', 'helfentalk');

Route::middleware(VerifyHeflenTalkSignature::class)->group(function () use ($prefix)
{
    // Read context (backward compatible).
    Route::post($prefix . '/connect', [ConnectController::class, 'handle'])
        ->name('helfentalk.connect');

    // Capability/schema manifest — tells HeflenTalk what the role may do.
    Route::post($prefix . '/manifest', [ManifestController::class, 'handle'])
        ->name('helfentalk.manifest');

    // Execute a single data action (query/count/create/update/delete).
    Route::post($prefix . '/action', [ActionController::class, 'handle'])
        ->name('helfentalk.action');
});

// User-context token endpoint. NOT HMAC-protected — it is guarded by YOUR OWN
// auth (config('helfentalk.token.middleware')) and mints a short-lived JWT for
// the logged-in user, so your chat UI never signs tokens itself. Disable via
// config('helfentalk.token.enabled').
$token = (array) config('helfentalk.token', []);

if ($token['enabled'] ?? false)
{
    Route::middleware($token['middleware'] ?? ['auth'])
        ->get($prefix . '/token', [TokenController::class, 'handle'])
        ->name('helfentalk.token');
}
