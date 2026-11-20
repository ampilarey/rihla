<?php

namespace Modules\Stays\Filament\Resources\Reviews\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Resources\Reviews\ReviewResource;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;
}
