<?php

namespace Modules\Stays\Filament\Host\Resources\Reviews\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Host\Resources\Reviews\ReviewResource;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;
}
