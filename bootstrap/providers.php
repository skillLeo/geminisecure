<?php

use App\Providers\AppServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    RateLimitServiceProvider::class,
    TenancyServiceProvider::class,
];
