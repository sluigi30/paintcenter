<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Concerns\ReturnsToTableAfterSave;
use App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    use ReturnsToTableAfterSave;

    protected static string $resource = CategoryResource::class;
}
