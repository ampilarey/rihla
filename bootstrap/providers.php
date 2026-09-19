<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\Filament\StaffPanelProvider;
use App\Providers\ViewServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    StaffPanelProvider::class,
    ViewServiceProvider::class,
];
