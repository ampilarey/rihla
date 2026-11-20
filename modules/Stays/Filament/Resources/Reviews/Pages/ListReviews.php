<?php

<<<<<<<< HEAD:modules/Stays/Filament/Resources/Reviews/Pages/ListReviews.php
namespace Modules\Stays\Filament\Resources\Reviews\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Resources\Reviews\ReviewResource;
========
namespace Modules\Stays\Filament\Host\Resources\Reviews\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Host\Resources\Reviews\ReviewResource;
>>>>>>>> origin/main:modules/Stays/Filament/Host/Resources/Reviews/Pages/ListReviews.php

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;
}
