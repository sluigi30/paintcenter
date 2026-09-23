<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;

class ListDrivers extends ListUsers
{
    protected static string $resource = DriverResource::class;
}
