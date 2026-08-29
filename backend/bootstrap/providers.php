<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\ObservabilityServiceProvider::class,
    FortifyServiceProvider::class,
];
