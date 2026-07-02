<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleArchive')
                ->label(fn () => $this->record->is_archived ? 'Activate' : 'Deactivate')
                ->icon(fn () => $this->record->is_archived ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
                ->color(fn () => $this->record->is_archived ? 'success' : 'warning')
                ->hidden(fn () => $this->record->id === auth()->id())
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['is_archived' => !$this->record->is_archived]);
                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
