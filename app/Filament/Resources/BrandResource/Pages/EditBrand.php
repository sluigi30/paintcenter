<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use App\Models\Brand;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleArchive')
                ->label(fn () => $this->record->is_archived ? 'Unarchive' : 'Archive')
                ->icon(fn () => $this->record->is_archived ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-archive-box')
                ->color(fn () => $this->record->is_archived ? 'success' : 'warning')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['is_archived' => !$this->record->is_archived]);
                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
