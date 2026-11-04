<?php

use App\Providers\AppServiceProvider;
use App\Providers\HeadquartersServiceProvider;
use App\Providers\HttpSecurityServiceProvider;

return [
    HttpSecurityServiceProvider::class,
    AppServiceProvider::class,
    HeadquartersServiceProvider::class,
];
