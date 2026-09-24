<?php

namespace App\Filament\Resources\TintColorResource\Pages;

use App\Filament\Concerns\ReturnsToTableAfterSave;
use App\Filament\Resources\TintColorResource;
use App\Models\TintColor;
use Filament\Resources\Pages\CreateRecord;

class CreateTintColor extends CreateRecord
{
    use ReturnsToTableAfterSave;

    protected static string $resource = TintColorResource::class;

    /** New presets go to the end of the list the customer sees. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort_order'] = (int) TintColor::max('sort_order') + 1;

        return $data;
    }
}
