<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLogs;

use AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource as BaseActivityLogResource;

class ActivityLogResource extends BaseActivityLogResource
{
    protected static bool $shouldRegisterNavigation = false;
}
