<?php

use HelfenTalk\Connect\Http\Controllers\ActionController;
use HelfenTalk\Connect\Http\Controllers\ConnectController;
use HelfenTalk\Connect\Http\Controllers\ManifestController;
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
