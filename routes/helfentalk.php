<?php

use HelfenTalk\Connect\Http\Controllers\ConnectController;
use HelfenTalk\Connect\Http\Middleware\VerifyHeflenTalkSignature;
use Illuminate\Support\Facades\Route;

$prefix = config('helfentalk.route_prefix', 'helfentalk');

Route::post($prefix . '/connect', [ConnectController::class, 'handle'])
    ->middleware(VerifyHeflenTalkSignature::class)
    ->name('helfentalk.connect');
