<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return UserResource::sanitizeManagedUserData($data);
    }
}
