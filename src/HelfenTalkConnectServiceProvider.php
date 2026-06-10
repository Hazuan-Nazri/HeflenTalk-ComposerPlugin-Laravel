<?php

namespace HelfenTalk\Connect;

use Illuminate\Support\ServiceProvider;

class HelfenTalkConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/helfentalk.php', 'helfentalk');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/helfentalk.php' => function_exists('config_path') ? config_path('helfentalk.php') : base_path('config/helfentalk.php'),
        ], 'helfentalk-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/helfentalk.php');
    }
}
