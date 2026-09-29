<?php

namespace App\Filament\Host\Resources\Reviews\Pages;

use App\Filament\Host\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;
}
