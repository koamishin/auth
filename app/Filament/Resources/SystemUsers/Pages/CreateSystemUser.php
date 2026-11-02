<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers\Pages;

use App\Filament\Resources\SystemUsers\SystemUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSystemUser extends CreateRecord
{
    protected static string $resource = SystemUserResource::class;

    protected static ?string $title = 'Create System Administrator';
}
