<?php

use App\Providers\AdminPortalServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AdminPortalServiceProvider::class,
];
