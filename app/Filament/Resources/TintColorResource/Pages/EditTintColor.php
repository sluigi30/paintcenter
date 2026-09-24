<?php

namespace App\Filament\Resources\TintColorResource\Pages;

use App\Filament\Concerns\ReturnsToTableAfterSave;
use App\Filament\Resources\TintColorResource;
use Filament\Resources\Pages\EditRecord;

class EditTintColor extends EditRecord
{
    use ReturnsToTableAfterSave;

    protected static string $resource = TintColorResource::class;
}
