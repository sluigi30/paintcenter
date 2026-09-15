<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\ReturnsToTableAfterSave;
use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ReturnsToTableAfterSave;

    protected static string $resource = ProductResource::class;
}
