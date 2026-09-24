<?php

namespace App\Filament\Resources\TintColorResource\Pages;

use App\Filament\Resources\TintColorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTintColors extends ListRecords
{
    protected static string $resource = TintColorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
