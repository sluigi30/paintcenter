<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Concerns\ReturnsToTableAfterSave;
use App\Filament\Resources\BrandResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBrand extends CreateRecord
{
    use ReturnsToTableAfterSave;

    protected static string $resource = BrandResource::class;
}
